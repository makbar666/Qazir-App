<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DailyReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function outlet(): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko Rekonsiliasi', 'created_at' => now(), 'updated_at' => now()]);
        $outlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Pusat', 'code' => 'PST', 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $product = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'K1', 'name' => 'Kopi', 'price' => 10000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->insert(['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 10, 'created_at' => now(), 'updated_at' => now()]);
        return [$business, $outlet, $owner, $product];
    }

    private function sell(int $product, string $method = 'cash'): void
    {
        $this->post('/checkout', [
            'request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product, 'quantity' => 1]],
            'discount' => 0, 'payment_method' => $method, 'amount_received' => 10000,
        ])->assertRedirect();
    }

    public function test_cash_shift_and_outlet_are_reconciled_and_later_changes_require_new_review(): void
    {
        [$business, $outlet, $owner, $product] = $this->outlet();
        $otherOutlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Cabang', 'code' => 'CBG', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->post('/shifts', ['opening_cash' => 50000])->assertRedirect();
        $shift = DB::table('cash_shifts')->first();
        $this->sell($product);
        $this->post('/shifts/'.$shift->id.'/close', ['actual_cash' => 60000])->assertRedirect();
        $date = app(DailyReconciliationService::class)->today();
        $summary = app(DailyReconciliationService::class)->summary($business, $outlet, $date);
        $this->assertSame(10000, $summary['gross']);
        $this->assertSame(0, $summary['cashLedgerDifference']);
        $this->assertSame([], $summary['blocking']);
        $this->assertSame(0, app(DailyReconciliationService::class)->summary($business, $otherOutlet, $date)['gross']);
        $this->post('/reconciliation/review', ['date' => $date])->assertRedirect();
        $this->assertDatabaseCount('daily_reconciliation_reviews', 1);
        $this->get('/reconciliation?date='.$date)->assertOk()->assertSee('Disahkan');
        $this->sell($product, 'qris');
        $this->get('/reconciliation?date='.$date)->assertOk()->assertSee('Perlu tinjau ulang');
        $this->post('/reconciliation/review', ['date' => $date])->assertSessionHasErrors('note');
        $this->post('/reconciliation/review', ['date' => $date, 'note' => 'QRIS manual sudah cocok dengan mutasi penyedia.'])->assertRedirect();
        $this->assertDatabaseCount('daily_reconciliation_reviews', 2);
        $this->post('/outlet/switch', ['outlet_id' => $otherOutlet])->assertRedirect();
        $this->post('/reconciliation/review', ['date' => $date, 'outlet_id' => $outlet, 'note' => 'Tab lama dari outlet pusat.'])->assertSessionHasErrors('outlet_id');
        $this->assertDatabaseCount('daily_reconciliation_reviews', 2);
    }

    public function test_cash_variance_needs_explanation_and_cashier_cannot_access(): void
    {
        [$business, $outlet, $owner, $product] = $this->outlet();
        $this->actingAs($owner)->post('/shifts', ['opening_cash' => 50000])->assertRedirect();
        $this->sell($product);
        $shift = DB::table('cash_shifts')->first();
        $this->post('/shifts/'.$shift->id.'/close', ['actual_cash' => 59000])->assertRedirect();
        $date = app(DailyReconciliationService::class)->today();
        $summary = app(DailyReconciliationService::class)->summary($business, $outlet, $date);
        $this->assertSame(-1000, $summary['shiftDifference']);
        $this->post('/reconciliation/review', ['date' => $date])->assertSessionHasErrors('note');
        $this->post('/reconciliation/review', ['date' => $date, 'note' => 'Kas fisik kurang seribu, akan diperiksa.'])->assertRedirect();
        $cashier = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'cashier']);
        $this->actingAs($cashier)->get('/reconciliation')->assertForbidden();
        $this->post('/reconciliation/check', ['date' => $date])->assertForbidden();
        $this->post('/reconciliation/review', ['date' => $date])->assertForbidden();
    }

    public function test_midtrans_qris_must_be_checked_before_review_and_uses_local_business_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 18:00:00', 'UTC'));
        [$business, $outlet, $owner, $product] = $this->outlet();
        DB::table('midtrans_settings')->insert(['business_id' => $business, 'environment' => 'sandbox', 'server_key' => encrypt('SB-Mid-server-test'), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $remoteFails = false;
        Http::fake([
            'api.sandbox.midtrans.com/v2/charge' => function ($request) {
                return Http::response(['order_id' => $request['transaction_details']['order_id'], 'transaction_id' => 'midtrans-456', 'transaction_status' => 'pending', 'actions' => [['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-456/qr-code']]], 201);
            },
            'api.sandbox.midtrans.com/v2/*/status' => function ($request) use (&$remoteFails) {
                if ($remoteFails) return Http::response(['status_message' => 'Unavailable'], 503);
                $order = basename(dirname(parse_url($request->url(), PHP_URL_PATH)));
                return Http::response(['order_id' => $order, 'transaction_id' => 'midtrans-456', 'payment_type' => 'qris', 'transaction_status' => 'settlement', 'status_code' => '200', 'gross_amount' => '10000.00'], 200);
            },
        ]);
        $this->actingAs($owner);
        $this->assertSame('2026-11-22', app(DailyReconciliationService::class)->today());
        $this->sell($product, 'qris');
        $date = '2026-11-22';
        $this->post('/reconciliation/review', ['date' => $date])->assertSessionHasErrors('reconciliation');
        $this->post('/reconciliation/check', ['date' => $date])->assertRedirect();
        $this->assertDatabaseHas('sales', ['outlet_id' => $outlet, 'payment_method' => 'qris', 'status' => 'paid']);
        $this->assertDatabaseHas('qris_reconciliation_checks', ['business_id' => $business, 'outlet_id' => $outlet, 'remote_status' => 'settlement', 'remote_amount' => 10000]);
        $summary = app(DailyReconciliationService::class)->summary($business, $outlet, $date);
        $this->assertSame(10000, $summary['qrisGross']);
        $this->assertSame([], $summary['blocking']);
        $this->post('/reconciliation/review', ['date' => $date])->assertRedirect();
        $this->assertDatabaseCount('daily_reconciliation_reviews', 1);
        $this->post('/reconciliation/check', ['date' => $date])->assertRedirect();
        $this->get('/reconciliation?date='.$date)->assertOk()->assertSee('Disahkan');
        $remoteFails = true;
        $this->post('/reconciliation/check', ['date' => $date])->assertRedirect();
        $this->assertNotEmpty(app(DailyReconciliationService::class)->summary($business, $outlet, $date)['blocking']);
        $this->get('/reconciliation?date='.$date)->assertOk()->assertSee('Perlu tinjau ulang');
        $this->post('/reconciliation/review', ['date' => $date])->assertSessionHasErrors('reconciliation');
        Carbon::setTestNow();
    }

    public function test_refund_on_next_day_is_counted_on_refund_day_without_erasing_original_payment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 03:00:00', 'UTC'));
        [$business, $outlet, $owner, $product] = $this->outlet();
        $this->actingAs($owner)->post('/shifts', ['opening_cash' => 20000])->assertRedirect();
        $firstShift = DB::table('cash_shifts')->first();
        $this->sell($product);
        $sale = DB::table('sales')->first();
        $this->post('/shifts/'.$firstShift->id.'/close', ['actual_cash' => 30000])->assertRedirect();
        Carbon::setTestNow(Carbon::parse('2026-11-22 03:00:00', 'UTC'));
        $this->post('/shifts', ['opening_cash' => 20000])->assertRedirect();
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect();
        $secondShift = DB::table('cash_shifts')->orderByDesc('id')->first();
        $this->post('/shifts/'.$secondShift->id.'/close', ['actual_cash' => 10000])->assertRedirect();
        $firstDay = app(DailyReconciliationService::class)->summary($business, $outlet, '2026-11-21');
        $secondDay = app(DailyReconciliationService::class)->summary($business, $outlet, '2026-11-22');
        $this->assertSame(10000, $firstDay['gross']);
        $this->assertSame(0, $firstDay['refundTotal']);
        $this->assertSame(0, $secondDay['gross']);
        $this->assertSame(10000, $secondDay['refundTotal']);
        $this->assertSame(0, $firstDay['cashLedgerDifference']);
        $this->assertSame(0, $secondDay['cashLedgerDifference']);
        Carbon::setTestNow();
    }

    public function test_shift_crossing_midnight_is_visible_on_both_days_and_variance_belongs_to_closing_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 16:00:00', 'UTC'));
        [$business, $outlet, $owner, $product] = $this->outlet();
        $this->actingAs($owner)->post('/shifts', ['opening_cash' => 20000])->assertRedirect();
        $this->sell($product);
        $shift = DB::table('cash_shifts')->first();
        Carbon::setTestNow(Carbon::parse('2026-11-21 18:00:00', 'UTC'));
        $this->post('/shifts/'.$shift->id.'/close', ['actual_cash' => 29000])->assertRedirect();
        $firstDay = app(DailyReconciliationService::class)->summary($business, $outlet, '2026-11-21');
        $secondDay = app(DailyReconciliationService::class)->summary($business, $outlet, '2026-11-22');
        $this->assertCount(1, $firstDay['shifts']);
        $this->assertCount(1, $secondDay['shifts']);
        $this->assertSame(0, $firstDay['shiftDifference']);
        $this->assertSame(-1000, $secondDay['shiftDifference']);
        $this->assertNotEmpty($firstDay['warnings']);
        Carbon::setTestNow();
    }

    public function test_unresolved_refund_from_earlier_qris_blocks_todays_review(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 03:00:00', 'UTC'));
        [$business, $outlet, $owner, $product] = $this->outlet();
        $this->actingAs($owner);
        $this->sell($product, 'qris');
        $sale = DB::table('sales')->first();
        DB::table('sales')->where('id', $sale->id)->update(['midtrans_status' => 'settlement', 'midtrans_transaction_id' => 'midtrans-old']);
        Carbon::setTestNow(Carbon::parse('2026-11-22 03:00:00', 'UTC'));
        DB::table('midtrans_refunds')->insert([
            'sale_id' => $sale->id, 'business_id' => $business, 'outlet_id' => $outlet,
            'requested_by' => $owner->id, 'refund_key' => 'refund-old-sale', 'amount' => 10000,
            'reason' => 'Barang dikembalikan', 'status' => 'request_failed', 'created_at' => $sale->paid_at, 'updated_at' => now(),
        ]);
        DB::table('qris_reconciliation_checks')->insert([
            'sale_id' => $sale->id, 'business_id' => $business, 'outlet_id' => $outlet,
            'remote_status' => 'settlement', 'remote_amount' => 10000, 'checked_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $summary = app(DailyReconciliationService::class)->summary($business, $outlet, '2026-11-22');
        $this->assertCount(1, $summary['qrisRows']);
        $this->assertNotEmpty($summary['blocking']);
        $this->assertStringContainsString('refund', $summary['qrisRows']->first()->problem);
        $this->post('/reconciliation/review', ['date' => '2026-11-22'])->assertSessionHasErrors('reconciliation');
        Carbon::setTestNow();
    }
}
