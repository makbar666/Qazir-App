<?php

namespace App\Http\Controllers;

use App\Services\TandaClient;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IntegrationController extends Controller
{
    public function __construct(private TandaClient $tanda){}
    private function owner(Request $r): void {abort_unless(RoleAccess::allowsMenu($r->user(),'integration'),403);}
    public function index(Request $r)
    {
        $this->owner($r);$c=$this->tanda->config($r->user()->business_id);$remote=[];
        if($c&&$c->active){try{$remote=$this->tanda->request($c,'GET','connection')['outlets']??[];}catch(Throwable $e){$remote=[];}}
        $outlets=DB::table('outlets')->where('business_id',$r->user()->business_id)->get();$maps=DB::table('loyalty_outlet_maps')->where('business_id',$r->user()->business_id)->pluck('remote_outlet_id','outlet_id');
        $sales=DB::table('sales')->where('business_id',$r->user()->business_id)->whereNotNull('member_external_id')->orderByDesc('id')->limit(20)->get();
        return view('integration',['connection'=>$c,'remoteOutlets'=>$remote,'outlets'=>$outlets,'maps'=>$maps,'sales'=>$sales]);
    }
    public function connect(Request $r)
    {
        $this->owner($r);$d=$r->validate(['base_url'=>'required|url|max:255','token'=>'required|string|max:255']);
        if(!preg_match('#^https?://#i',$d['base_url']))throw ValidationException::withMessages(['base_url'=>'Gunakan URL HTTP atau HTTPS.']);
        $temporary=(object)['base_url'=>rtrim($d['base_url'],'/'),'token'=>encrypt($d['token'])];
        try{$info=$this->tanda->request($temporary,'GET','connection');}catch(Throwable $e){throw ValidationException::withMessages(['token'=>'Koneksi gagal: '.$e->getMessage()]);}
        DB::table('loyalty_connections')->updateOrInsert(['business_id'=>$r->user()->business_id],['base_url'=>$temporary->base_url,'token'=>$temporary->token,'remote_business_id'=>$info['business_id'],'remote_business_name'=>$info['business_name'],'active'=>true,'last_checked_at'=>now(),'last_error'=>null,'updated_at'=>now(),'created_at'=>now()]);
        return back()->with('success','Tanda terhubung. Pasangkan outlet di bawah.');
    }
    public function map(Request $r)
    {
        $this->owner($r);$d=$r->validate(['outlet_id'=>'required|integer','remote_outlet_id'=>'required|integer']);$business=$r->user()->business_id;
        if(!DB::table('outlets')->where('business_id',$business)->where('id',$d['outlet_id'])->exists())abort(422);
        $c=$this->tanda->config($business);abort_unless($c&&$c->active,422);
        $remote=$this->tanda->request($c,'GET','connection')['outlets']??[];
        if(!collect($remote)->contains('id',$d['remote_outlet_id']))throw ValidationException::withMessages(['remote_outlet_id'=>'Outlet Tanda tidak valid.']);
        DB::table('loyalty_outlet_maps')->updateOrInsert(['outlet_id'=>$d['outlet_id']],['business_id'=>$business,'remote_outlet_id'=>$d['remote_outlet_id'],'updated_at'=>now(),'created_at'=>now()]);return back()->with('success','Outlet berhasil dipasangkan.');
    }
    public function member(Request $r)
    {
        $r->validate(['q'=>'required|string|min:3|max:50']);$c=$this->tanda->config($r->user()->business_id);abort_unless($c&&$c->active,404);
        try{return response()->json($this->tanda->request($c,'GET','members',['q'=>$r->q]));}catch(Throwable $e){return response()->json(['message'=>'Pencarian member gagal.'],503);}
    }
    public function retry(Request $r,int $id)
    {
        $this->owner($r);$sale=DB::table('sales')->where('business_id',$r->user()->business_id)->where('id',$id)->first();abort_unless($sale,404);$ok=$this->tanda->sync($id);return back()->with($ok?'success':'error',$ok?'Sinkronisasi berhasil.':'Sinkronisasi gagal. Periksa status dan pesan galat.');
    }
}
