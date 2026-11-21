<?php

namespace App\Http\Controllers;

use App\Services\MidtransService;
use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class MidtransController extends Controller
{
    public function __construct(private MidtransService $midtrans){}
    private function owner(Request $r): void {abort_unless(RoleAccess::allowsMenu($r->user(),'midtrans'),403);}
    private function sale(Request $r,int $id): object {$sale=DB::table('sales')->where('business_id',$r->user()->business_id)->where('outlet_id',OutletContext::id($r))->where('id',$id)->where('payment_method','qris')->first();abort_unless($sale,404);return $sale;}
    public function settings(Request $r) {$this->owner($r);return view('midtrans-settings',['settings'=>DB::table('midtrans_settings')->where('business_id',$r->user()->business_id)->first()]);}
    public function storeSettings(Request $r)
    {
        $this->owner($r);$d=$r->validate(['environment'=>['required',Rule::in(['sandbox','production'])],'server_key'=>'nullable|string|min:10|max:255','active'=>'required|boolean']);
        $existing=DB::table('midtrans_settings')->where('business_id',$r->user()->business_id)->first();
        if(!$existing && empty($d['server_key']))return back()->withErrors(['server_key'=>'Server Key wajib diisi.']);
        if($existing && ($existing->environment!==$d['environment'] || !empty($d['server_key'])) && (DB::table('sales')->where('business_id',$r->user()->business_id)->where('payment_method','qris')->where('status','pending')->whereNotNull('midtrans_transaction_id')->exists()
            || DB::table('midtrans_refunds')->where('business_id',$r->user()->business_id)->where('status','!=','confirmed')->exists()))
            return back()->withErrors(['server_key'=>'Selesaikan pembayaran dan refund QRIS yang masih menunggu sebelum mengubah lingkungan atau Server Key.']);
        DB::table('midtrans_settings')->updateOrInsert(['business_id'=>$r->user()->business_id],['environment'=>$d['environment'],'server_key'=>!empty($d['server_key'])?encrypt($d['server_key']):$existing->server_key,'active'=>$d['active'],'updated_at'=>now(),'created_at'=>$existing->created_at??now()]);
        return back()->with('success','Pengaturan Midtrans disimpan.');
    }
    public function payment(Request $r,int $id)
    {
        $sale=$this->sale($r,$id);if($sale->status!=='pending')return redirect()->route('sales.show',$id);
        if($sale->midtrans_transaction_id&&!$sale->midtrans_qr_url){
            try{$this->midtrans->status($id);}catch(Throwable $e){}
            $sale=$this->sale($r,$id);
            if($sale->status!=='pending')return redirect()->route('sales.show',$id);
        }
        return view('payment',['sale'=>$sale]);
    }
    public function state(Request $r,int $id)
    {
        $sale=$this->sale($r,$id);
        return response()->json(['status'=>$sale->status]);
    }
    public function check(Request $r,int $id)
    {
        $sale=$this->sale($r,$id);
        try{$this->midtrans->status($sale->id);}catch(Throwable $e){return back()->with('error',$e->getMessage());}
        $sale=$this->sale($r,$id);
        return $sale->status==='paid'?redirect()->route('sales.show',$id)->with('success','Pembayaran QRIS sudah lunas.'):back()->with('success','Status pembayaran: '.($sale->midtrans_status??$sale->status));
    }
    public function retry(Request $r,int $id)
    {
        $sale=$this->sale($r,$id);abort_unless($sale->status==='pending' && !$sale->midtrans_qr_url,422);
        try{$this->midtrans->status($id);}catch(Throwable $e){}
        $sale=$this->sale($r,$id);
        if($sale->status==='pending' && !$sale->midtrans_qr_url && !$sale->midtrans_transaction_id)$this->midtrans->createCharge($id);
        return back();
    }
    public function cancel(Request $r,int $id)
    {
        $sale=$this->sale($r,$id);
        if($sale->status!=='pending')return redirect()->route('sales.show',$id);
        try{$this->midtrans->cancel($id);}catch(Throwable $e){return back()->with('error',$e->getMessage());}
        $sale=$this->sale($r,$id);
        if($sale->status==='pending')return back()->with('error','Midtrans belum memastikan pembatalan. Periksa status pembayaran dan coba lagi.');
        return redirect()->route('sales.show',$id)->with('success',$sale->status==='cancelled'?'Transaksi dibatalkan dan stok dikembalikan.':'Pembayaran sudah lunas; transaksi tidak dapat dibatalkan.');
    }
    public function webhook(Request $r)
    {
        try{$this->midtrans->webhook($r->all());return response()->json(['ok'=>true]);}
        catch(RuntimeException $e){return response()->json(['error'=>$e->getMessage()],422);}
    }
}
