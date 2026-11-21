<?php

namespace App\Http\Controllers;

use App\Services\LoyaltyReconciliationService;
use App\Services\TandaClient;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class LoyaltyReconciliationController extends Controller
{
    public function __construct(private LoyaltyReconciliationService $service, private TandaClient $tanda) {}

    private function allowed(Request $request): void
    {
        abort_unless(RoleAccess::allowsMenu($request->user(),'integration'),403);
    }

    public function index(Request $request, ?int $runId=null)
    {
        $this->allowed($request);
        $business=$request->user()->business_id;
        $runs=DB::table('loyalty_reconciliations')->where('business_id',$business)->orderByDesc('id')->limit(10)->get();
        $run=$runId ? DB::table('loyalty_reconciliations')->where('business_id',$business)->where('id',$runId)->first() : $runs->first();
        if ($runId) abort_unless($run,404);
        $items=$run ? DB::table('loyalty_reconciliation_items as i')->join('sales as s','s.id','=','i.sale_id')
            ->join('outlets as o','o.id','=','s.outlet_id')
            ->where('i.loyalty_reconciliation_id',$run->id)->where('s.business_id',$business)
            ->select('i.*','s.number','s.status as sale_status','s.loyalty_status','s.loyalty_refund_status',
                's.total','s.redeem_points','s.loyalty_earned_points','o.name as outlet_name')
            ->orderByRaw("CASE i.result WHEN 'review' THEN 0 WHEN 'retry' THEN 1 ELSE 2 END")
            ->orderByDesc('i.id')->paginate(30) : null;
        $connection=$this->tanda->config($business);
        $actions=$run ? DB::table('loyalty_reconciliation_actions as a')
            ->join('loyalty_reconciliation_items as i','i.id','=','a.loyalty_reconciliation_item_id')
            ->join('sales as s','s.id','=','i.sale_id')->join('users as u','u.id','=','a.actor_id')
            ->where('i.loyalty_reconciliation_id',$run->id)->where('s.business_id',$business)
            ->select('a.*','s.number','u.name as actor_name')->orderByDesc('a.id')->limit(20)->get() : collect();
        return view('loyalty-reconciliation',compact('runs','run','items','connection','actions'));
    }

    public function check(Request $request)
    {
        $this->allowed($request);
        $data=$request->validate(['date_from'=>'required|date','date_to'=>'required|date|after_or_equal:date_from']);
        if (\Carbon\Carbon::parse($data['date_from'])->diffInDays($data['date_to'])>30) {
            throw ValidationException::withMessages(['date_to'=>'Rentang pemeriksaan maksimal 31 hari.']);
        }
        $connection=$this->tanda->config($request->user()->business_id);
        if (!$connection?->active) throw ValidationException::withMessages(['date_from'=>'Hubungkan Tanda terlebih dahulu.']);
        try {
            $id=$this->service->check($connection,$request->user()->business_id,$data['date_from'],$data['date_to'],$request->user()->id);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['date_from'=>'Pemeriksaan gagal: '.$e->getMessage()]);
        }
        return redirect()->route('integration.reconciliation.show',$id)->with('success','Pemeriksaan selesai. Hasil tersimpan sebagai riwayat.');
    }

    public function retry(Request $request, int $itemId)
    {
        $this->allowed($request);
        $business=$request->user()->business_id;
        $item=DB::table('loyalty_reconciliation_items as i')
            ->join('loyalty_reconciliations as r','r.id','=','i.loyalty_reconciliation_id')
            ->join('sales as s','s.id','=','i.sale_id')
            ->where('i.id',$itemId)->where('r.business_id',$business)->where('s.business_id',$business)
            ->select('i.id','i.result','i.loyalty_reconciliation_id','s.id as sale_id','s.status','s.request_key',
                's.redeem_points','s.member_external_id','s.loyalty_transaction_id','s.loyalty_status','s.loyalty_refund_status')
            ->first();
        abort_unless($item,404);
        abort_unless($item->result==='retry',422);
        $connection=$this->tanda->config($business);
        abort_unless($connection?->active,422);
        try {
            $remote=$this->service->snapshot($connection,[(string)$item->sale_id],
                $item->redeem_points?[$item->request_key]:[]);
            $sale=DB::table('sales')->where('business_id',$business)->where('id',$item->sale_id)->first();
            $map=DB::table('loyalty_outlet_maps')->where('business_id',$business)->where('outlet_id',$sale->outlet_id)->value('remote_outlet_id');
            [$result]=$this->service->compare($sale,$remote['sales'][(string)$sale->id]??null,
                $remote['reservations'][$sale->request_key]??null,$map);
            if ($result!=='retry') {
                $this->recordAction($item->id,$request->user()->id,'skipped','Kondisi Tanda berubah; pengiriman ulang dibatalkan.');
                return back()->with('error','Kondisi berubah. Jalankan pemeriksaan baru sebelum mencoba lagi.');
            }
            $ok=$sale->status==='cancelled' ? $this->tanda->release($sale->id) : $this->tanda->sync($sale->id);
        } catch (Throwable $e) {
            report($e);
            $this->recordAction($item->id,$request->user()->id,'failed','Permintaan ke Tanda gagal.');
            return back()->with('error','Sinkronisasi gagal: '.$e->getMessage());
        }
        $this->recordAction($item->id,$request->user()->id,$ok?'success':'failed',
            $ok?'Pengiriman ulang diterima. Periksa kembali untuk memastikan hasil.':'Pengiriman ulang gagal.');
        return back()->with($ok?'success':'error',$ok
            ? 'Pengiriman ulang berhasil. Jalankan pemeriksaan baru untuk memastikan hasil.'
            : 'Pengiriman ulang gagal. Periksa detail penjualan dan coba lagi.');
    }

    private function recordAction(int $itemId,int $actorId,string $result,string $note): void
    {
        DB::table('loyalty_reconciliation_actions')->insert([
            'loyalty_reconciliation_item_id'=>$itemId,'actor_id'=>$actorId,'result'=>$result,
            'note'=>$note,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
