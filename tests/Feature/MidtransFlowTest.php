<?php

namespace Tests\Feature;

use App\Events\SalePaymentUpdated;
use App\Models\User;
use App\Services\TandaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MidtransFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([SalePaymentUpdated::class]);
    }

    private function setupSale(bool $omitActions=false): array
    {
        $business=DB::table('businesses')->insertGetId(['name'=>'Toko Uji','created_at'=>now(),'updated_at'=>now()]);
        $outlet=DB::table('outlets')->insertGetId(['business_id'=>$business,'name'=>'Utama','code'=>'UTM','created_at'=>now(),'updated_at'=>now()]);
        $user=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'owner']);
        $product=DB::table('products')->insertGetId(['business_id'=>$business,'sku'=>'K1','name'=>'Kopi','price'=>13000,'point_eligible'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('stocks')->insert(['outlet_id'=>$outlet,'product_id'=>$product,'quantity'=>5,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('midtrans_settings')->insert(['business_id'=>$business,'environment'=>'sandbox','server_key'=>encrypt('SB-Mid-server-test'),'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        Http::fake([
            'api.sandbox.midtrans.com/v2/charge'=>function($request)use($omitActions){$order=$request['transaction_details']['order_id'];return Http::response(['order_id'=>$order,'transaction_id'=>'midtrans-123','transaction_status'=>'pending','actions'=>$omitActions?[]:[['name'=>'generate-qr-code','url'=>'https://api.sandbox.midtrans.com/v2/qris/midtrans-123/qr-code']]],201);},
            'api.sandbox.midtrans.com/v2/qris/*/qr-code'=>Http::response('png',200,['Content-Type'=>'image/png']),
        ]);
        $this->actingAs($user)->post('/checkout',['request_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$product,'quantity'=>1]],'discount'=>0,'payment_method'=>'qris','amount_received'=>0])->assertRedirect();
        return [$product,DB::table('sales')->first()];
    }

    private function notification(object $sale,string $status): array
    {
        $code=$status==='settlement'?'200':'202';$amount=$sale->total.'.00';
        return ['order_id'=>$sale->number,'transaction_id'=>'midtrans-123','payment_type'=>'qris','transaction_status'=>$status,'status_code'=>$code,'gross_amount'=>$amount,'signature_key'=>hash('sha512',$sale->number.$code.$amount.'SB-Mid-server-test')];
    }

    public function test_qris_is_pending_until_signed_settlement_and_retry_is_safe(): void
    {
        [$product,$sale]=$this->setupSale();
        $this->assertSame('pending',$sale->status);
        $this->assertSame(0,$sale->amount_received);
        $this->assertNotNull($sale->midtrans_qr_url);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>4]);
        $bad=$this->notification($sale,'settlement');$bad['signature_key']='invalid';
        $this->postJson('/api/midtrans/notification',$bad)->assertStatus(422);
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'pending']);
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'settlement'))->assertOk();
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'settlement'))->assertOk();
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'paid','amount_received'=>13000]);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>4]);
        $this->assertDatabaseCount('stock_movements',1);
        Event::assertDispatchedTimes(SalePaymentUpdated::class,1);
    }

    public function test_expired_qris_releases_reserved_stock_once(): void
    {
        [$product,$sale]=$this->setupSale();
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'expire'))->assertOk();
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'expire'))->assertOk();
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'cancelled']);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
        $this->assertDatabaseCount('stock_movements',2);
        Event::assertDispatchedTimes(SalePaymentUpdated::class,1);
        $this->post('/sales/'.$sale->id.'/refund',['reason'=>'Pembayaran batal'])->assertStatus(422);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
    }

    public function test_signed_notification_with_wrong_amount_does_not_mark_sale_paid(): void
    {
        [, $sale]=$this->setupSale();
        $payload=$this->notification($sale,'settlement');
        $payload['gross_amount']='14000.00';
        $payload['signature_key']=hash('sha512',$sale->number.'200'.$payload['gross_amount'].'SB-Mid-server-test');
        $this->postJson('/api/midtrans/notification',$payload)->assertStatus(422);
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'pending']);
    }

    public function test_failed_charge_page_loads_and_can_be_cancelled_once(): void
    {
        [$product,$sale]=$this->setupSale();
        DB::table('sales')->where('id',$sale->id)->update([
            'midtrans_transaction_id'=>null,'midtrans_qr_url'=>null,
            'midtrans_status'=>'charge_failed','midtrans_error'=>'Midtrans menolak Server Key (HTTP 401)',
        ]);
        $this->get('/sales/'.$sale->id.'/payment')->assertOk()->assertSee('Batalkan transaksi');
        $this->post('/sales/'.$sale->id.'/payment/cancel')->assertRedirect('/sales/'.$sale->id);
        $this->post('/sales/'.$sale->id.'/payment/cancel')->assertRedirect('/sales/'.$sale->id);
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'cancelled']);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
        $this->assertDatabaseCount('stock_movements',2);
    }

    public function test_midtrans_cancel_confirmation_releases_stock_once(): void
    {
        [$product,$sale]=$this->setupSale();
        Http::fake(['api.sandbox.midtrans.com/v2/*/cancel'=>Http::response($this->notification($sale,'cancel'),200)]);
        $this->get('/sales/'.$sale->id.'/payment')->assertOk();
        $this->post('/sales/'.$sale->id.'/payment/cancel')->assertRedirect('/sales/'.$sale->id);
        $this->postJson('/api/midtrans/notification',$this->notification($sale,'cancel'))->assertOk();
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'cancelled']);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
        $this->assertDatabaseCount('stock_movements',2);
    }

    public function test_existing_midtrans_transaction_recovers_its_qr_image(): void
    {
        [, $sale]=$this->setupSale();
        DB::table('sales')->where('id',$sale->id)->update(['midtrans_qr_url'=>null,'midtrans_status'=>'charge_failed']);
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/status'=>Http::response($this->notification($sale,'pending'),200),
            'api.sandbox.midtrans.com/v2/qris/*/qr-code'=>Http::response('png',200,['Content-Type'=>'image/png']),
        ]);
        $this->get('/sales/'.$sale->id.'/payment')->assertOk()->assertSee('QRIS dinamis untuk pembayaran');
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'midtrans_status'=>'pending']);
        $this->assertNotNull(DB::table('sales')->where('id',$sale->id)->value('midtrans_qr_url'));
    }

    public function test_charge_recovers_qr_when_midtrans_omits_actions(): void
    {
        [, $sale]=$this->setupSale(true);
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'midtrans_status'=>'pending','midtrans_transaction_id'=>'midtrans-123']);
        $this->assertNotNull(DB::table('sales')->where('id',$sale->id)->value('midtrans_qr_url'));
    }

    public function test_midtrans_server_error_keeps_stock_reserved_and_marks_cancel_requested(): void
    {
        [$product,$sale]=$this->setupSale();
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/cancel'=>Http::response(['status_code'=>'500','status_message'=>'Temporarily unavailable'],500),
            'api.sandbox.midtrans.com/v2/*/status'=>Http::response($this->notification($sale,'pending'),200),
        ]);
        $this->post('/sales/'.$sale->id.'/payment/cancel')->assertRedirect();
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'pending','midtrans_status'=>'cancel_requested']);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>4]);
        $this->get('/sales/'.$sale->id.'/payment')->assertOk()->assertSee('Pembatalan diminta')->assertDontSee('QRIS dinamis untuk pembayaran');
    }

    public function test_expire_api_finishes_cancellation_when_cancel_api_fails(): void
    {
        [$product,$sale]=$this->setupSale();
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/cancel'=>Http::response(['status_code'=>'500'],500),
            'api.sandbox.midtrans.com/v2/*/expire'=>Http::response(['transaction_status'=>'expire','status_code'=>'407'],200),
            'api.sandbox.midtrans.com/v2/*/status'=>Http::response($this->notification($sale,'expire'),200),
        ]);
        $this->post('/sales/'.$sale->id.'/payment/cancel')->assertRedirect('/sales/'.$sale->id);
        $this->assertDatabaseHas('sales',['id'=>$sale->id,'status'=>'cancelled','midtrans_status'=>'expire']);
        $this->assertDatabaseHas('stocks',['product_id'=>$product,'quantity'=>5]);
        $this->assertDatabaseCount('stock_movements',2);
    }

    public function test_websocket_channel_is_limited_to_the_sales_outlet(): void
    {
        config(['broadcasting.default'=>'reverb']);
        require base_path('routes/channels.php');
        [, $sale]=$this->setupSale();
        $payload=['socket_id'=>'123.456','channel_name'=>'private-sales.'.$sale->id];
        $this->post('/broadcasting/auth',$payload)->assertOk();

        $otherBusiness=DB::table('businesses')->insertGetId(['name'=>'Toko Lain','created_at'=>now(),'updated_at'=>now()]);
        $otherOutlet=DB::table('outlets')->insertGetId(['business_id'=>$otherBusiness,'name'=>'Lain','code'=>'OTH','created_at'=>now(),'updated_at'=>now()]);
        $other=User::factory()->create(['business_id'=>$otherBusiness,'outlet_id'=>$otherOutlet,'role'=>'owner']);
        $this->assertSame($otherBusiness,$other->business_id);
        $this->assertSame($otherOutlet,$other->outlet_id);
        $this->actingAs($other)->get('/sales/'.$sale->id.'/payment/state')->assertNotFound();
        $this->post('/broadcasting/auth',$payload)->assertForbidden();
    }

    private function refundNotification(object $sale, string $refundKey, bool $confirmed = false): array
    {
        $payload = $this->notification($sale, 'refund');
        $payload['status_code'] = '200';
        $payload['signature_key'] = hash('sha512', $sale->number.'200'.$payload['gross_amount'].'SB-Mid-server-test');
        $payload['refund_amount'] = $sale->total.'.00';
        $payload['refunds'] = [[
            'refund_key' => $refundKey, 'refund_amount' => $sale->total.'.00',
        ]];
        if ($confirmed) $payload['refunds'][0]['bank_confirmed_at'] = now()->toDateTimeString();
        return $payload;
    }

    public function test_qris_refund_waits_for_bank_confirmation_before_restoring_stock(): void
    {
        [$product, $sale] = $this->setupSale();
        $this->postJson('/api/midtrans/notification', $this->notification($sale, 'settlement'))->assertOk();
        $calls = 0;
        Http::fake(['api.sandbox.midtrans.com/v2/*/refund' => function ($request) use ($sale, &$calls) {
            $calls++;
            return Http::response([
                'status_code' => '200', 'order_id' => $sale->number, 'payment_type' => 'qris',
                'transaction_status' => 'refund', 'gross_amount' => $sale->total.'.00',
                'refund_amount' => $sale->total.'.00', 'refund_key' => $request['refund_key'],
            ], 200);
        }]);
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect('/sales/'.$sale->id);
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect('/sales/'.$sale->id);
        $this->assertSame(1, $calls);
        $refund = DB::table('midtrans_refunds')->where('sale_id', $sale->id)->first();
        $this->assertSame('pending', $refund->status);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 4]);
        $this->get('/sales/'.$sale->id)->assertOk()->assertSee('Menunggu konfirmasi bank');

        $this->postJson('/api/midtrans/notification', $this->refundNotification($sale, $refund->refund_key))->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 4]);
        $this->postJson('/api/midtrans/notification', $this->refundNotification($sale, $refund->refund_key, true))->assertOk();
        $this->postJson('/api/midtrans/notification', $this->refundNotification($sale, $refund->refund_key, true))->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'refunded', 'refund_reason' => 'Barang dikembalikan']);
        $this->assertDatabaseHas('midtrans_refunds', ['sale_id' => $sale->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 5]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->get('/sales/'.$sale->id)->assertOk()->assertSee('Dikonfirmasi Midtrans');
    }

    public function test_refund_mismatch_requires_manual_review_without_reversing_sale(): void
    {
        [$product, $sale] = $this->setupSale();
        $this->postJson('/api/midtrans/notification', $this->notification($sale, 'settlement'))->assertOk();
        Http::fake(['api.sandbox.midtrans.com/v2/*/refund' => Http::response(['status_code' => '500', 'status_message' => 'Sementara gagal'], 500)]);
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect();
        $refund = DB::table('midtrans_refunds')->where('sale_id', $sale->id)->first();
        $this->assertSame('request_failed', $refund->status);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 4]);

        $wrong = $this->refundNotification($sale, 'other-key', true);
        $this->postJson('/api/midtrans/notification', $wrong)->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
        $this->assertDatabaseHas('midtrans_refunds', ['sale_id' => $sale->id, 'status' => 'review']);
        $this->post('/sales/'.$sale->id.'/refund/retry')->assertRedirect();
        $this->assertDatabaseCount('midtrans_refunds', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 4]);
    }

    public function test_failed_refund_request_can_retry_with_the_same_key(): void
    {
        [$product, $sale] = $this->setupSale();
        $this->postJson('/api/midtrans/notification', $this->notification($sale, 'settlement'))->assertOk();
        $fail = true;
        $keys = [];
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/refund' => function ($request) use ($sale, &$fail, &$keys) {
                $keys[] = $request['refund_key'];
                if ($fail) return Http::response(['status_code' => '500', 'status_message' => 'Sementara gagal'], 500);
                return Http::response([
                    'status_code' => '200', 'order_id' => $sale->number, 'payment_type' => 'qris',
                    'transaction_status' => 'refund', 'gross_amount' => $sale->total.'.00',
                    'refund_amount' => $sale->total.'.00', 'refund_key' => $request['refund_key'],
                ], 200);
            },
            'api.sandbox.midtrans.com/v2/*/status' => Http::response($this->notification($sale, 'settlement'), 200),
        ]);
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect();
        $refund = DB::table('midtrans_refunds')->where('sale_id', $sale->id)->first();
        $this->assertSame('request_failed', $refund->status);
        $fail = false;
        $this->post('/sales/'.$sale->id.'/refund/retry')->assertRedirect();
        $this->assertSame([$refund->refund_key, $refund->refund_key], $keys);
        $this->assertDatabaseHas('midtrans_refunds', ['id' => $refund->id, 'status' => 'pending']);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 4]);
    }

    public function test_refund_status_check_confirms_only_matching_full_amount(): void
    {
        [$product, $sale] = $this->setupSale();
        $this->postJson('/api/midtrans/notification', $this->notification($sale, 'settlement'))->assertOk();
        Http::fake(['api.sandbox.midtrans.com/v2/*/refund' => function ($request) use ($sale) {
            return Http::response([
                'status_code' => '200', 'order_id' => $sale->number, 'payment_type' => 'qris',
                'transaction_status' => 'refund', 'gross_amount' => $sale->total.'.00',
                'refund_amount' => $sale->total.'.00', 'refund_key' => $request['refund_key'],
            ], 200);
        }]);
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect();
        $refund = DB::table('midtrans_refunds')->where('sale_id', $sale->id)->first();
        $statusAmount = '5000.00';
        Http::fake(['api.sandbox.midtrans.com/v2/*/status' => function () use ($sale, $refund, &$statusAmount) {
            $payload = $this->refundNotification($sale, $refund->refund_key, true);
            $payload['refunds'][0]['refund_amount'] = $statusAmount;
            return Http::response($payload, 200);
        }]);
        $this->post('/sales/'.$sale->id.'/refund/check')->assertRedirect();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 4]);
        $statusAmount = $sale->total.'.00';
        $this->post('/sales/'.$sale->id.'/refund/check')->assertRedirect();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('stocks', ['product_id' => $product, 'quantity' => 5]);
    }

    public function test_tanda_refund_sync_runs_only_after_midtrans_bank_confirmation(): void
    {
        [, $sale] = $this->setupSale();
        $this->postJson('/api/midtrans/notification', $this->notification($sale, 'settlement'))->assertOk();
        DB::table('sales')->where('id', $sale->id)->update(['member_external_id' => 42]);
        $tanda = \Mockery::mock(TandaClient::class);
        $tanda->shouldReceive('sync')->once()->with($sale->id)->andReturn(true);
        app()->instance(TandaClient::class, $tanda);

        Http::fake(['api.sandbox.midtrans.com/v2/*/refund' => function ($request) use ($sale) {
            return Http::response([
                'status_code' => '200', 'order_id' => $sale->number, 'payment_type' => 'qris',
                'transaction_status' => 'refund', 'gross_amount' => $sale->total.'.00',
                'refund_amount' => $sale->total.'.00', 'refund_key' => $request['refund_key'],
            ], 200);
        }]);
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect();
        $refund = DB::table('midtrans_refunds')->where('sale_id', $sale->id)->first();
        $this->postJson('/api/midtrans/notification', $this->refundNotification($sale, $refund->refund_key))->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
        $this->postJson('/api/midtrans/notification', $this->refundNotification($sale, $refund->refund_key, true))->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'refunded']);
    }

    public function test_refund_request_is_limited_to_authorized_staff_and_sale_outlet(): void
    {
        [, $sale] = $this->setupSale();
        $this->postJson('/api/midtrans/notification', $this->notification($sale, 'settlement'))->assertOk();
        $cashier = User::factory()->create(['business_id' => $sale->business_id, 'outlet_id' => $sale->outlet_id, 'role' => 'cashier']);
        $this->actingAs($cashier)->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertForbidden();
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherOutlet = DB::table('outlets')->insertGetId(['business_id' => $otherBusiness, 'name' => 'Lain', 'code' => 'OTH', 'created_at' => now(), 'updated_at' => now()]);
        $otherOwner = User::factory()->create(['business_id' => $otherBusiness, 'outlet_id' => $otherOutlet, 'role' => 'owner']);
        $this->actingAs($otherOwner)->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertNotFound();
        $this->assertDatabaseCount('midtrans_refunds', 0);
    }
}
