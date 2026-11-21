<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private function setupStore(): array
    {
        $business=DB::table('businesses')->insertGetId(['name'=>'Toko Uji','created_at'=>now(),'updated_at'=>now()]);
        $outlet=DB::table('outlets')->insertGetId(['business_id'=>$business,'name'=>'Utama','code'=>'UTM','created_at'=>now(),'updated_at'=>now()]);
        $owner=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'owner']);
        $product=DB::table('products')->insertGetId(['business_id'=>$business,'sku'=>'A1','name'=>'Kopi','price'=>20000,'point_eligible'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('stocks')->insert(['outlet_id'=>$outlet,'product_id'=>$product,'quantity'=>5,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('loyalty_connections')->insert(['business_id'=>$business,'base_url'=>'http://tanda.test','token'=>encrypt('token-test'),'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('loyalty_outlet_maps')->insert(['business_id'=>$business,'outlet_id'=>$outlet,'remote_outlet_id'=>7,'created_at'=>now(),'updated_at'=>now()]);
        return [$owner,$product];
    }

    private function checkout(int $product): array
    {
        return ['request_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$product,'quantity'=>1]],'discount'=>0,'payment_method'=>'cash','amount_received'=>20000,'member_external_id'=>15,'redeem_points'=>10];
    }

    public function test_cash_sale_reserves_points_before_finalizing_and_syncs_discount(): void
    {
        [$owner,$product]=$this->setupStore();
        Http::fake([
            'http://tanda.test/api/v1/pos/redemptions'=>Http::response(['status'=>'reserved','points'=>10,'redeem_rupiah'=>1000,'total'=>19000]),
            'http://tanda.test/api/v1/pos/sales'=>Http::response(['transaction_id'=>55,'earned_points'=>1,'balance_after'=>12]),
        ]);
        $payload=$this->checkout($product);
        $this->actingAs($owner)->post('/checkout',$payload)->assertRedirect();
        $sale=DB::table('sales')->first();
        $this->assertSame('paid',$sale->status);
        $this->assertSame(19000,$sale->total);
        $this->assertSame(10,$sale->redeem_points);
        $this->assertSame(1000,$sale->redeem_rupiah);
        $this->assertSame(1000,$sale->change_amount);
        $this->assertSame('synced',$sale->loyalty_status);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>4]);
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/redemptions') && $request['external_key']===$payload['request_key'] && $request['eligible_subtotal']===20000);
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/sales') && $request['redeem_points']===10 && $request['redeem_rupiah']===1000 && $request['total']===19000);
    }

    public function test_failed_reservation_cancels_draft_and_restores_stock(): void
    {
        [$owner,$product]=$this->setupStore();
        Http::fake([
            'http://tanda.test/api/v1/pos/redemptions'=>Http::response(['message'=>'Saldo poin tidak cukup.'],422),
            'http://tanda.test/api/v1/pos/redemptions/*/release'=>Http::response(['status'=>'released']),
        ]);
        $this->actingAs($owner)->post('/checkout',$this->checkout($product))->assertSessionHasErrors('redeem_points');
        $this->assertDatabaseHas('sales',['status'=>'cancelled','loyalty_status'=>'released']);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
        $this->assertDatabaseHas('stock_movements',['product_id'=>$product,'delta'=>1,'reason'=>'loyalty_reserve_failed']);
    }

    private function qrisSale(): array
    {
        [$owner,$product]=$this->setupStore();
        DB::table('midtrans_settings')->insert(['business_id'=>$owner->business_id,'environment'=>'sandbox','server_key'=>encrypt('SB-Mid-server-test'),'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        Event::fake();
        Http::fake([
            'http://tanda.test/api/v1/pos/redemptions'=>Http::response(['status'=>'reserved','points'=>10,'redeem_rupiah'=>1000,'total'=>19000]),
            'http://tanda.test/api/v1/pos/redemptions/*/release'=>Http::response(['status'=>'released']),
            'http://tanda.test/api/v1/pos/sales'=>Http::response(['transaction_id'=>55,'earned_points'=>1,'balance_after'=>12]),
            'api.sandbox.midtrans.com/v2/charge'=>function($request){$order=$request['transaction_details']['order_id'];return Http::response(['order_id'=>$order,'transaction_id'=>'midtrans-123','transaction_status'=>'pending','actions'=>[['name'=>'generate-qr-code','url'=>'https://api.sandbox.midtrans.com/v2/qris/midtrans-123/qr-code']]],201);},
        ]);
        $payload=$this->checkout($product);$payload['payment_method']='qris';$payload['amount_received']=0;
        $this->actingAs($owner)->post('/checkout',$payload)->assertRedirect();
        return [$product,DB::table('sales')->first()];
    }

    private function notification(object $sale,string $status): array
    {
        $code=$status==='settlement'?'200':'202';$amount=$sale->total.'.00';
        return ['order_id'=>$sale->number,'transaction_id'=>'midtrans-123','payment_type'=>'qris','transaction_status'=>$status,'status_code'=>$code,'gross_amount'=>$amount,'signature_key'=>hash('sha512',$sale->number.$code.$amount.'SB-Mid-server-test')];
    }

    public function test_qris_redemption_is_confirmed_only_after_payment(): void
    {
        [$product,$sale]=$this->qrisSale();
        $this->assertSame('pending',$sale->status);
        $this->assertSame('reserved',$sale->loyalty_status);
        $this->assertSame(19000,$sale->total);
        Http::assertNotSent(fn($request)=>str_ends_with($request->url(),'/sales'));
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'settlement'))->assertOk();
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'paid','loyalty_status'=>'synced','amount_received'=>19000]);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>4]);
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/sales') && $request['redeem_points']===10);
    }

    public function test_expired_qris_releases_points_and_stock(): void
    {
        [$product,$sale]=$this->qrisSale();
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'expire'))->assertOk();
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'expire'))->assertOk();
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'cancelled','loyalty_status'=>'released']);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/redemptions/'.$sale->request_key.'/release'));
        Http::assertNotSent(fn($request)=>str_ends_with($request->url(),'/sales'));
    }
}
