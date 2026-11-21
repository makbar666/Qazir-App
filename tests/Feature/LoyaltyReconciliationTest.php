<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function store(): array
    {
        $business=DB::table('businesses')->insertGetId(['name'=>'Toko','created_at'=>now(),'updated_at'=>now()]);
        $outlet=DB::table('outlets')->insertGetId(['business_id'=>$business,'name'=>'Pusat','code'=>'PST','created_at'=>now(),'updated_at'=>now()]);
        $owner=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'owner']);
        DB::table('loyalty_connections')->insert(['business_id'=>$business,'base_url'=>'http://tanda.test','token'=>encrypt('token-test'),'remote_business_id'=>123,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('loyalty_outlet_maps')->insert(['business_id'=>$business,'outlet_id'=>$outlet,'remote_outlet_id'=>7,'created_at'=>now(),'updated_at'=>now()]);
        return [$business,$outlet,$owner];
    }

    private function sale(int $business,int $outlet,int $cashier,string $status='paid',array $extra=[]): int
    {
        return DB::table('sales')->insertGetId(array_merge([
            'business_id'=>$business,'outlet_id'=>$outlet,'cashier_id'=>$cashier,
            'request_key'=>(string)Str::uuid(),'number'=>'POS-'.Str::random(8),'status'=>$status,
            'subtotal'=>20000,'discount'=>0,'total'=>20000,'payment_method'=>'cash',
            'amount_received'=>20000,'member_external_id'=>'15','loyalty_status'=>'pending',
            'created_at'=>now(),'updated_at'=>now(),
        ],$extra));
    }

    private function remote(int $id,string $status='paid',array $extra=[]): array
    {
        return array_merge(['external_sale_id'=>(string)$id,'transaction_id'=>55,'status'=>$status,
            'member_id'=>15,'outlet_id'=>7,'total'=>20000,'earned_points'=>2,
            'redeem_points'=>0,'redeem_rupiah'=>0],$extra);
    }

    public function test_check_saves_snapshot_and_separates_safe_retry_from_manual_review(): void
    {
        [$business,$outlet,$owner]=$this->store();
        $ok=$this->sale($business,$outlet,$owner->id,'paid',['loyalty_status'=>'synced','loyalty_transaction_id'=>'55','loyalty_earned_points'=>2]);
        $missing=$this->sale($business,$outlet,$owner->id);
        $different=$this->sale($business,$outlet,$owner->id,'paid',['loyalty_status'=>'synced','loyalty_transaction_id'=>'56']);
        $refunded=$this->sale($business,$outlet,$owner->id,'refunded',['loyalty_status'=>'synced','loyalty_transaction_id'=>'57','loyalty_refund_status'=>'failed']);
        Http::fake(['http://tanda.test/api/v1/pos/reconciliation'=>Http::response(['business_id'=>123,'sales'=>[
            (string)$ok=>$this->remote($ok),
            (string)$missing=>null,
            (string)$different=>$this->remote($different,'paid',['total'=>19000,'transaction_id'=>56]),
            (string)$refunded=>$this->remote($refunded,'paid',['transaction_id'=>57]),
        ],'reservations'=>[]])]);
        $this->actingAs($owner)->post(route('integration.reconciliation.check'),[
            'date_from'=>today()->toDateString(),'date_to'=>today()->toDateString(),
        ])->assertRedirect();
        $run=DB::table('loyalty_reconciliations')->first();
        $this->assertSame(4,$run->checked_count);
        $this->assertSame(1,$run->ok_count);
        $this->assertSame(2,$run->retry_count);
        $this->assertSame(1,$run->review_count);
        $this->assertDatabaseHas('loyalty_reconciliation_items',['sale_id'=>$missing,'result'=>'retry']);
        $this->assertDatabaseHas('loyalty_reconciliation_items',['sale_id'=>$different,'result'=>'review']);
        $this->get(route('integration.reconciliation.show',$run->id))->assertOk()->assertSee('Rekonsiliasi poin')->assertSee('Tinjau manual');
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/reconciliation') && count($request['sale_ids'])===4);
    }

    public function test_retry_rechecks_remote_state_and_refuses_changed_mismatch(): void
    {
        [$business,$outlet,$owner]=$this->store();
        $sale=$this->sale($business,$outlet,$owner->id);
        Http::fake(['http://tanda.test/api/v1/pos/reconciliation'=>Http::sequence()
            ->push(['business_id'=>123,'sales'=>[(string)$sale=>null],'reservations'=>[]])
            ->push(['business_id'=>123,'sales'=>[(string)$sale=>$this->remote($sale,'paid',['total'=>19000])],'reservations'=>[]])]);
        $this->actingAs($owner)->post(route('integration.reconciliation.check'),[
            'date_from'=>today()->toDateString(),'date_to'=>today()->toDateString(),
        ])->assertRedirect();
        $item=DB::table('loyalty_reconciliation_items')->first();
        $this->post(route('integration.reconciliation.retry',$item->id))->assertSessionHas('error');
        $this->assertDatabaseHas('sales',['id'=>$sale,'loyalty_status'=>'pending']);
        $this->assertDatabaseHas('loyalty_reconciliation_actions',['loyalty_reconciliation_item_id'=>$item->id,'result'=>'skipped']);
        Http::assertSentCount(2);
    }

    public function test_other_business_and_cashier_cannot_read_or_retry(): void
    {
        [$business,$outlet,$owner]=$this->store();
        $sale=$this->sale($business,$outlet,$owner->id);
        Http::fake(['http://tanda.test/api/v1/pos/reconciliation'=>Http::response(['business_id'=>123,'sales'=>[(string)$sale=>null],'reservations'=>[]])]);
        $this->actingAs($owner)->post(route('integration.reconciliation.check'),[
            'date_from'=>today()->toDateString(),'date_to'=>today()->toDateString(),
        ])->assertRedirect();
        $run=DB::table('loyalty_reconciliations')->first();
        $item=DB::table('loyalty_reconciliation_items')->first();
        $cashier=User::factory()->create(['business_id'=>$business,'outlet_id'=>$outlet,'role'=>'cashier']);
        $this->actingAs($cashier)->get(route('integration.reconciliation.show',$run->id))->assertForbidden();
        $this->post(route('integration.reconciliation.retry',$item->id))->assertForbidden();
        [$otherBusiness,$otherOutlet,$otherOwner]=$this->store();
        $this->actingAs($otherOwner)->get(route('integration.reconciliation.show',$run->id))->assertNotFound();
        $this->post(route('integration.reconciliation.retry',$item->id))->assertNotFound();
    }

    public function test_safe_retry_uses_existing_idempotent_sale_sync(): void
    {
        [$business,$outlet,$owner]=$this->store();
        $sale=$this->sale($business,$outlet,$owner->id);
        $product=DB::table('products')->insertGetId(['business_id'=>$business,'sku'=>'K1','name'=>'Kopi',
            'price'=>20000,'point_eligible'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('sale_items')->insert(['sale_id'=>$sale,'product_id'=>$product,'sku'=>'K1','name'=>'Kopi',
            'quantity'=>1,'unit_price'=>20000,'line_total'=>20000,'point_eligible'=>true]);
        Http::fake([
            'http://tanda.test/api/v1/pos/reconciliation'=>Http::response(['business_id'=>123,'sales'=>[(string)$sale=>null],'reservations'=>[]]),
            'http://tanda.test/api/v1/pos/sales'=>Http::response(['transaction_id'=>55,'earned_points'=>2,'balance_after'=>12]),
        ]);
        $this->actingAs($owner)->post(route('integration.reconciliation.check'),[
            'date_from'=>today()->toDateString(),'date_to'=>today()->toDateString(),
        ])->assertRedirect();
        $item=DB::table('loyalty_reconciliation_items')->first();
        $this->post(route('integration.reconciliation.retry',$item->id))->assertSessionHas('success');
        $this->assertDatabaseHas('sales',['id'=>$sale,'loyalty_status'=>'synced','loyalty_transaction_id'=>'55']);
        $this->assertDatabaseHas('loyalty_reconciliation_actions',['loyalty_reconciliation_item_id'=>$item->id,'result'=>'success']);
        $this->get(route('integration.reconciliation.show',DB::table('loyalty_reconciliations')->first()->id))
            ->assertOk()->assertSee('Riwayat tindakan')->assertSee('Pengiriman ulang diterima');
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/sales') && $request['external_sale_id']===(string)$sale);
    }

    public function test_cancelled_sale_releases_stuck_point_reservation(): void
    {
        [$business,$outlet,$owner]=$this->store();
        $sale=$this->sale($business,$outlet,$owner->id,'cancelled',[
            'redeem_points'=>10,'redeem_rupiah'=>1000,'loyalty_status'=>'release_pending',
        ]);
        $key=DB::table('sales')->where('id',$sale)->value('request_key');
        Http::fake([
            'http://tanda.test/api/v1/pos/reconciliation'=>Http::response(['business_id'=>123,'sales'=>[(string)$sale=>null],
                'reservations'=>[$key=>['status'=>'reserved','points'=>10]]]),
            'http://tanda.test/api/v1/pos/redemptions/*/release'=>Http::response(['status'=>'released']),
        ]);
        $this->actingAs($owner)->post(route('integration.reconciliation.check'),[
            'date_from'=>today()->toDateString(),'date_to'=>today()->toDateString(),
        ])->assertRedirect();
        $item=DB::table('loyalty_reconciliation_items')->first();
        $this->assertSame('retry',$item->result);
        $this->post(route('integration.reconciliation.retry',$item->id))->assertSessionHas('success');
        $this->assertDatabaseHas('sales',['id'=>$sale,'loyalty_status'=>'released']);
    }

    public function test_incomplete_remote_response_does_not_create_a_false_result(): void
    {
        [$business,$outlet,$owner]=$this->store();
        $this->sale($business,$outlet,$owner->id);
        Http::fake(['http://tanda.test/api/v1/pos/reconciliation'=>Http::response(['sales'=>[],'reservations'=>[]])]);
        $this->actingAs($owner)->post(route('integration.reconciliation.check'),[
            'date_from'=>today()->toDateString(),'date_to'=>today()->toDateString(),
        ])->assertSessionHasErrors('date_from');
        $this->assertDatabaseCount('loyalty_reconciliations',0);
    }

    public function test_connection_to_another_tanda_business_is_rejected(): void
    {
        [$business,$outlet,$owner]=$this->store();
        $sale=$this->sale($business,$outlet,$owner->id);
        Http::fake(['http://tanda.test/api/v1/pos/reconciliation'=>Http::response([
            'business_id'=>999,'sales'=>[(string)$sale=>null],'reservations'=>[],
        ])]);
        $this->actingAs($owner)->post(route('integration.reconciliation.check'),[
            'date_from'=>today()->toDateString(),'date_to'=>today()->toDateString(),
        ])->assertSessionHasErrors('date_from');
        $this->assertDatabaseCount('loyalty_reconciliations',0);
    }
}
