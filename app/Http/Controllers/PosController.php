<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TandaClient;
use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PosController extends Controller
{
    public function login() { return view('login'); }
    public function authenticate(Request $r) { $credentials = $r->validate(['email'=>'required|email','password'=>'required']); if (!Auth::attempt([...$credentials,'is_active'=>true])) return back()->withErrors(['email'=>'Email atau kata sandi salah, atau akun dinonaktifkan.']); $r->session()->regenerate(); return redirect()->route(RoleAccess::homeRoute($r->user())); }
    public function logout(Request $r) { Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect()->route('login'); }
    private function outlet(Request $r): int { return OutletContext::id($r); }
    private function manager(Request $r): void { abort_unless(RoleAccess::allowsAbility($r->user(), 'products_manage'), 403); }
    public function dashboard(Request $r) { $business = $r->user()->business_id; $outlet = $this->outlet($r); $today = DB::table('sales')->where('business_id',$business)->where('outlet_id',$outlet)->whereDate('created_at',today())->where('status','paid'); return view('dashboard',['count'=>(clone $today)->count(),'revenue'=>(clone $today)->sum('total'),'products'=>DB::table('products')->where('business_id',$business)->whereNull('deleted_at')->count(),'recent'=>DB::table('sales')->where('business_id',$business)->where('outlet_id',$outlet)->orderByDesc('id')->limit(8)->get(),'lowStockCount'=>RoleAccess::allowsMenu($r->user(),'stock_alerts')?app(\App\Services\StockAlertService::class)->lowForOutlet($business,$outlet)->count():0]); }
    public function products(Request $r)
    {
        $archived=$r->query('archived')==='1';
        if($archived)$this->manager($r);
        $query=DB::table('products as p')
            ->leftJoin('stocks as s',fn($j)=>$j->on('s.product_id','=','p.id')->where('s.outlet_id',$this->outlet($r)))
            ->where('p.business_id',$r->user()->business_id);
        $archived?$query->whereNotNull('p.deleted_at'):$query->whereNull('p.deleted_at');
        return view('products',['products'=>$query->select('p.*','s.quantity')->orderBy('p.name')->get(),'archived'=>$archived]);
    }
    public function storeProduct(Request $r)
    {
        $this->manager($r);
        $data=$r->validate(['sku'=>'required|string|max:40','name'=>'required|string|max:120','price'=>'required|integer|min:0','point_eligible'=>'nullable|boolean']);
        $business=$r->user()->business_id;
        $existing=DB::table('products')->where('business_id',$business)->where('sku',$data['sku'])->first();
        if($existing)throw ValidationException::withMessages(['sku'=>$existing->deleted_at?'SKU pernah digunakan. Pulihkan produk dari daftar dihapus.':'SKU sudah digunakan.']);
        DB::table('products')->insert(['business_id'=>$business,'sku'=>$data['sku'],'name'=>$data['name'],'price'=>$data['price'],'point_eligible'=>$data['point_eligible']??false,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        return back()->with('success','Produk ditambahkan.');
    }
    public function updateProduct(Request $r,int $id)
    {
        $this->manager($r);
        $business=$r->user()->business_id;
        abort_unless(DB::table('products')->where('business_id',$business)->where('id',$id)->whereNull('deleted_at')->exists(),404);
        $data=$r->validate([
            'sku'=>['required','string','max:40',Rule::unique('products','sku')->where('business_id',$business)->ignore($id)],
            'name'=>'required|string|max:120',
            'price'=>'required|integer|min:0|max:999999999999',
            'point_eligible'=>'required|boolean',
            'active'=>'required|boolean',
        ]);
        DB::table('products')->where('business_id',$business)->where('id',$id)->update($data+['updated_at'=>now()]);
        return back()->with('success','Produk diperbarui.');
    }
    public function destroyProduct(Request $r,int $id)
    {
        $this->manager($r);
        $updated=DB::table('products')->where('business_id',$r->user()->business_id)->where('id',$id)->whereNull('deleted_at')
            ->update(['deleted_at'=>now(),'updated_at'=>now()]);
        abort_unless($updated,404);
        return redirect()->route('products')->with('success','Produk dihapus dari katalog. Riwayat transaksi dan stok tetap tersimpan.');
    }
    public function restoreProduct(Request $r,int $id)
    {
        $this->manager($r);
        $updated=DB::table('products')->where('business_id',$r->user()->business_id)->where('id',$id)->whereNotNull('deleted_at')
            ->update(['deleted_at'=>null,'updated_at'=>now()]);
        abort_unless($updated,404);
        return redirect()->route('products', ['archived'=>1])->with('success','Produk dipulihkan ke katalog.');
    }
    public function adjustStock(Request $r,int $id)
    {
        $this->manager($r);
        $data=$r->validate(['delta'=>'required|integer|not_in:0']);
        $product=DB::table('products')->where('business_id',$r->user()->business_id)->where('id',$id)->whereNull('deleted_at')->first();
        abort_unless($product,404);
        DB::transaction(function()use($r,$id,$data){
            $outlet=$this->outlet($r);
            DB::table('stocks')->insertOrIgnore(['outlet_id'=>$outlet,'product_id'=>$id,'quantity'=>0,'created_at'=>now(),'updated_at'=>now()]);
            $stock=DB::table('stocks')->where('outlet_id',$outlet)->where('product_id',$id)->lockForUpdate()->first();
            if($stock->quantity+$data['delta']<0)throw ValidationException::withMessages(['delta'=>'Stok tidak cukup.']);
            DB::table('stocks')->where('id',$stock->id)->update(['quantity'=>$stock->quantity+$data['delta'],'updated_at'=>now()]);
            DB::table('stock_movements')->insert([
                'business_id'=>$r->user()->business_id,'outlet_id'=>$outlet,'product_id'=>$id,'actor_id'=>$r->user()->id,
                'delta'=>$data['delta'],'reason'=>$data['delta']>0?'Stok ditambah manual':'Stok dikurangi manual',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
        return back()->with('success','Stok diperbarui.');
    }
    public function checkout(Request $r) { $products=DB::table('products as p')->join('stocks as s',fn($j)=>$j->on('s.product_id','=','p.id')->where('s.outlet_id',$this->outlet($r)))->where('p.business_id',$r->user()->business_id)->where('p.active',true)->whereNull('p.deleted_at')->where('s.quantity','>',0)->select('p.*','s.quantity')->orderBy('p.name')->get(); $integrationReady=DB::table('loyalty_connections')->where('business_id',$r->user()->business_id)->where('active',true)->exists()&&DB::table('loyalty_outlet_maps')->where('business_id',$r->user()->business_id)->where('outlet_id',$this->outlet($r))->exists(); $midtransReady=(bool)app(\App\Services\MidtransService::class)->settings($r->user()->business_id); $shifts=app(\App\Services\CashShiftService::class); $outlet=$this->outlet($r); return view('checkout',['products'=>$products,'integrationReady'=>$integrationReady,'midtransReady'=>$midtransReady,'cashShiftOpen'=>(bool)$shifts->active($r->user(),$outlet),'cashShiftEnforced'=>$shifts->enforced($outlet)]); }
    public function storeSale(Request $r)
    {
        $data=$r->validate([
            'request_key'=>'required|uuid','items'=>'required|array|min:1',
            'items.*.product_id'=>'required|integer','items.*.quantity'=>'required|integer|min:1|max:1000',
            'discount'=>'required|integer|min:0','payment_method'=>['required',Rule::in(['cash','transfer','qris','card'])],
            'amount_received'=>'nullable|integer|min:0','member_external_id'=>'nullable|integer|min:1','redeem_points'=>'nullable|integer|min:0',
        ]);
        $business=$r->user()->business_id;
        $dynamicQris=$data['payment_method']==='qris' && app(\App\Services\MidtransService::class)->settings($business);
        $redeemPoints=(int)($data['redeem_points']??0);
        if($redeemPoints&&empty($data['member_external_id']))throw ValidationException::withMessages(['redeem_points'=>'Pilih member Tanda sebelum menukar poin.']);
        if(!empty($data['member_external_id'])&&!DB::table('loyalty_outlet_maps')->where('business_id',$business)->where('outlet_id',$this->outlet($r))->exists())
            throw ValidationException::withMessages(['member_external_id'=>'Outlet belum dipasangkan dengan Tanda.']);
        $existing=DB::table('sales')->where('business_id',$business)->where('request_key',$data['request_key'])->first();
        if($existing)return $existing->status==='pending_loyalty'?redirect()->route('checkout')->with('error','Reservasi poin masih diproses. Coba transaksi baru setelah beberapa saat.'):redirect()->route($existing->status==='pending'?'sales.payment':'sales.show',$existing->id);
        $sale=DB::transaction(function()use($r,$data,$business,$dynamicQris,$redeemPoints){
            $existing=DB::table('sales')->where('business_id',$business)->where('request_key',$data['request_key'])->first();
            if($existing)return $existing->id;
            $quantities=[];
            foreach($data['items'] as $item)$quantities[$item['product_id']]=($quantities[$item['product_id']]??0)+$item['quantity'];
            $rows=[];$subtotal=0;$outlet=$this->outlet($r);
            foreach($quantities as $id=>$qty){
                $product=DB::table('products')->where('business_id',$business)->where('id',$id)->where('active',true)->whereNull('deleted_at')->first();
                if(!$product)throw ValidationException::withMessages(['items'=>'Produk tidak valid.']);
                $stock=DB::table('stocks')->where('outlet_id',$outlet)->where('product_id',$id)->lockForUpdate()->first();
                if(!$stock||$stock->quantity<$qty)throw ValidationException::withMessages(['items'=>'Stok '.$product->name.' tidak cukup.']);
                $rows[]=[$product,$stock,$qty];$subtotal+=$product->price*$qty;
            }
            if($data['discount']>$subtotal)throw ValidationException::withMessages(['discount'=>'Diskon melebihi subtotal.']);
            $total=$subtotal-$data['discount'];
            if($dynamicQris && $total<1)throw ValidationException::withMessages(['discount'=>'QRIS memerlukan total minimal Rp1.']);
            $received=(int)($data['amount_received']??0);
            if(!$dynamicQris && !$redeemPoints && $received<$total)throw ValidationException::withMessages(['amount_received'=>'Jumlah diterima kurang dari tagihan.']);
            $shift=app(\App\Services\CashShiftService::class)->forSale($r->user(),$outlet,$data['payment_method']==='cash');
            $id=DB::table('sales')->insertGetId([
                'business_id'=>$business,'outlet_id'=>$outlet,'cashier_id'=>$r->user()->id,'cash_shift_id'=>$shift?->id,'request_key'=>$data['request_key'],
                'number'=>'POS-'.now()->format('ymd').'-'.strtoupper(Str::random(8)),
                'status'=>$redeemPoints?'pending_loyalty':($dynamicQris?'pending':'paid'),'subtotal'=>$subtotal,'discount'=>$data['discount'],'total'=>$total,
                'payment_method'=>$data['payment_method'],'amount_received'=>$dynamicQris?0:$received,
                'change_amount'=>$dynamicQris||$redeemPoints?0:$received-$total,'member_external_id'=>$data['member_external_id']??null,
                'redeem_points'=>$redeemPoints,'redeem_rupiah'=>0,
                'loyalty_status'=>$redeemPoints?'reserving':(!$dynamicQris&&!empty($data['member_external_id'])?'pending':'disabled'),
                'midtrans_status'=>$dynamicQris?'new':null,'paid_at'=>$dynamicQris||$redeemPoints?null:now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($rows as [$product,$stock,$qty]){
                DB::table('sale_items')->insert(['sale_id'=>$id,'product_id'=>$product->id,'sku'=>$product->sku,'name'=>$product->name,'quantity'=>$qty,'unit_price'=>$product->price,'line_total'=>$product->price*$qty,'point_eligible'=>$product->point_eligible]);
                DB::table('stocks')->where('id',$stock->id)->decrement('quantity',$qty);
                DB::table('stock_movements')->insert(['business_id'=>$business,'outlet_id'=>$outlet,'product_id'=>$product->id,'sale_id'=>$id,'actor_id'=>$r->user()->id,'delta'=>-$qty,'reason'=>$dynamicQris||$redeemPoints?'payment_reserve':'sale','created_at'=>now(),'updated_at'=>now()]);
            }
            if($data['payment_method']==='cash' && !$redeemPoints)app(\App\Services\CashShiftService::class)->recordSale($id);
            return $id;
        },3);
        if($redeemPoints){
            $client=app(TandaClient::class);
            try{
                $reservation=$client->reserve($sale,$r->user()->role);
                $saleRow=DB::table('sales')->where('id',$sale)->first();
                $redeemRupiah=(int)$reservation['redeem_rupiah'];
                $total=$saleRow->subtotal-$saleRow->discount-$redeemRupiah;
                if(($reservation['status']??null)!=='reserved'||(int)($reservation['points']??0)!==$redeemPoints||(int)($reservation['total']??-1)!==$total||$redeemRupiah<1||$total<0||($dynamicQris&&$total<1))throw new \RuntimeException('Nilai penukaran tidak valid untuk pembayaran ini.');
                if(!$dynamicQris&&$saleRow->amount_received<$total)throw new \RuntimeException('Jumlah diterima kurang dari tagihan setelah penukaran poin.');
                DB::transaction(function()use($sale,$dynamicQris,$total,$redeemRupiah,$saleRow){
                    $updated=DB::table('sales')->where('id',$sale)->where('status','pending_loyalty')->update([
                        'status'=>$dynamicQris?'pending':'paid','total'=>$total,'redeem_rupiah'=>$redeemRupiah,
                        'amount_received'=>$dynamicQris?0:$saleRow->amount_received,
                        'change_amount'=>$dynamicQris?0:$saleRow->amount_received-$total,
                        'paid_at'=>$dynamicQris?null:now(),'loyalty_status'=>$dynamicQris?'reserved':'pending','updated_at'=>now(),
                    ]);
                    if(!$updated)throw new \RuntimeException('Status transaksi berubah saat reservasi poin.');
                    if(!$dynamicQris)app(\App\Services\CashShiftService::class)->recordSale($sale);
                },3);
            }catch(Throwable $e){
                $client->abortDraft($sale);
                throw ValidationException::withMessages(['redeem_points'=>'Penukaran poin gagal: '.$e->getMessage()]);
            }
        }
        if($dynamicQris){app(\App\Services\MidtransService::class)->createCharge($sale);return redirect()->route('sales.payment',$sale);}
        if(!empty($data['member_external_id']))app(TandaClient::class)->sync($sale);
        return redirect()->route('sales.show',$sale)->with('success','Penjualan berhasil.');
    }
    public function sales(Request $r) { $query=DB::table('sales as s')->leftJoin('midtrans_refunds as mr','mr.sale_id','=','s.id')->where('s.business_id',$r->user()->business_id)->where('s.outlet_id',$this->outlet($r)); if($r->filled('date'))$query->whereDate('s.created_at',$r->date); return view('sales',['sales'=>$query->select('s.*','mr.status as refund_status')->orderByDesc('s.id')->paginate(25),'date'=>$r->date]); }
    public function showSale(Request $r,int $id) { $sale=DB::table('sales')->where('business_id',$r->user()->business_id)->where('outlet_id',$this->outlet($r))->where('id',$id)->first(); abort_unless($sale,404); if($sale->status==='pending_loyalty')return redirect()->route('sales')->with('error','Reservasi poin masih diproses.'); if($sale->status==='pending')return redirect()->route('sales.payment',$id); return view('sale',['sale'=>$sale,'items'=>DB::table('sale_items')->where('sale_id',$id)->get(),'midtransRefund'=>DB::table('midtrans_refunds')->where('sale_id',$id)->first()]); }
    public function refund(Request $r,int $id)
    {
        abort_unless(RoleAccess::allowsAbility($r->user(), 'sales_refund'), 403);
        $data=$r->validate(['reason'=>'required|string|min:5|max:255']);
        $sale=DB::table('sales')->where('business_id',$r->user()->business_id)->where('outlet_id',$this->outlet($r))->where('id',$id)->first();
        abort_unless($sale,404);
        if($sale->status==='refunded')return back()->with('success','Refund sudah dicatat.');
        abort_unless($sale->status==='paid',422);
        if($sale->payment_method==='qris' && ($sale->midtrans_transaction_id || $sale->midtrans_status==='settlement')){
            try{app(\App\Services\MidtransService::class)->requestRefund($id,$r->user()->id,$data['reason']);}
            catch(Throwable $e){return back()->with('error',$e->getMessage());}
            return redirect()->route('sales.show',$id)->with('success','Permintaan refund dikirim. Tunggu konfirmasi bank dari Midtrans sebelum stok dan poin dipulihkan.');
        }
        DB::transaction(function()use($r,$id,$data){
            $sale=DB::table('sales')->where('business_id',$r->user()->business_id)->where('outlet_id',$this->outlet($r))->where('id',$id)->lockForUpdate()->first();
            abort_unless($sale,404);
            if($sale->status==='refunded')return;
            abort_unless($sale->status==='paid',422);
            $shift=app(\App\Services\CashShiftService::class)->forSale($r->user(),$sale->outlet_id,$sale->payment_method==='cash');
            app(\App\Services\SaleRefundService::class)->complete($id,$r->user()->id,$data['reason'],$shift?->id);
        },3);
        $sale=DB::table('sales')->where('id',$id)->first();
        if($sale?->member_external_id)app(TandaClient::class)->sync($id);
        return back()->with('success','Refund dicatat dan stok dikembalikan. Pastikan pengembalian uang sudah dilakukan.');
    }
    public function checkRefund(Request $r,int $id)
    {
        abort_unless(RoleAccess::allowsAbility($r->user(),'sales_refund'),403);
        $sale=DB::table('sales')->where('business_id',$r->user()->business_id)->where('outlet_id',$this->outlet($r))->where('id',$id)->where('payment_method','qris')->first();
        abort_unless($sale && DB::table('midtrans_refunds')->where('sale_id',$id)->exists(),404);
        try{app(\App\Services\MidtransService::class)->status($id);}
        catch(Throwable $e){return back()->with('error',$e->getMessage());}
        return back()->with('success',DB::table('sales')->where('id',$id)->value('status')==='refunded'?'Refund dikonfirmasi Midtrans.':'Refund belum dikonfirmasi bank.');
    }
    public function retryRefund(Request $r,int $id)
    {
        abort_unless(RoleAccess::allowsAbility($r->user(),'sales_refund'),403);
        $sale=DB::table('sales')->where('business_id',$r->user()->business_id)->where('outlet_id',$this->outlet($r))->where('id',$id)->where('payment_method','qris')->first();
        abort_unless($sale && DB::table('midtrans_refunds')->where('sale_id',$id)->exists(),404);
        try{app(\App\Services\MidtransService::class)->retryRefund($id);}
        catch(Throwable $e){return back()->with('error',$e->getMessage());}
        return back()->with('success','Status refund diperiksa. Permintaan dikirim ulang dengan kunci yang sama bila masih diperlukan.');
    }
    public function report(Request $r) { $date=$r->date?:today()->toDateString(); $base=DB::table('sales')->where('business_id',$r->user()->business_id)->where('outlet_id',$this->outlet($r))->whereDate('created_at',$date); return view('report',['date'=>$date,'paid'=>(clone $base)->where('status','paid')->count(),'revenue'=>(clone $base)->where('status','paid')->sum('total'),'refunds'=>(clone $base)->where('status','refunded')->count(),'byMethod'=>(clone $base)->where('status','paid')->select('payment_method',DB::raw('COUNT(*) as count'),DB::raw('SUM(total) as total'))->groupBy('payment_method')->get()]); }
}

