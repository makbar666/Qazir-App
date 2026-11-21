<?php

namespace Tests\Feature;

use App\Mail\LowStockDigest;
use App\Models\User;
use App\Services\StockAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAlertTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko', 'created_at' => now(), 'updated_at' => now()]);
        $outlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Pusat', 'code' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Cabang', 'code' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $product = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'A1', 'name' => 'Sabun', 'price' => 10000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->insert(['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
        return compact('business', 'outlet', 'branch', 'owner', 'product');
    }

    public function test_outlet_threshold_and_incoming_orders_drive_reorder_suggestion(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->get('/stock-alerts')->assertOk()->assertSee('Stok menipis');
        $this->put('/stock-alerts/rules/'.$product, ['minimum_quantity' => 4, 'target_quantity' => 10])->assertRedirect();
        $low = app(StockAlertService::class)->lowForOutlet($business, $outlet);
        $this->assertSame(1, $low->count());
        $this->assertSame(8, $low->first()->suggested_quantity);
        $this->get('/')->assertOk()->assertSee('1 produk mencapai batas stok minimum');
        $supplier = DB::table('suppliers')->insertGetId(['business_id' => $business, 'name' => 'Pemasok', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/purchases/create?suggested=1')->assertOk()->assertSee('name="items['.$product.'][quantity]"', false)->assertSee('value="8"', false);

        $this->post('/purchases', ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'items' => [['product_id' => $product, 'quantity' => 5, 'unit_cost' => 6000]]])->assertRedirect();
        $low = app(StockAlertService::class)->lowForOutlet($business, $outlet);
        $this->assertSame(5, $low->first()->incoming_quantity);
        $this->assertSame(3, $low->first()->suggested_quantity);

        $order = DB::table('purchase_orders')->first();
        $item = DB::table('purchase_order_items')->first();
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 2]])->assertRedirect();
        $this->assertSame(1, app(StockAlertService::class)->lowForOutlet($business, $outlet)->count());
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 3]])->assertRedirect();
        $this->assertSame(0, app(StockAlertService::class)->lowForOutlet($business, $outlet)->count());

        $this->post('/outlet/switch', ['outlet_id' => $branch])->assertRedirect();
        $this->assertSame(0, app(StockAlertService::class)->lowForOutlet($business, $branch)->count());
        $this->put('/stock-alerts/rules/'.$product, ['minimum_quantity' => 1, 'target_quantity' => 4])->assertRedirect();
        $this->assertSame(1, app(StockAlertService::class)->lowForOutlet($business, $branch)->count());
        $this->assertSame(4, app(StockAlertService::class)->lowForOutlet($business, $branch)->first()->suggested_quantity);
        $this->put('/stock-alerts/rules/'.$product, ['minimum_quantity' => ''])->assertRedirect();
        $this->assertDatabaseMissing('stock_alert_rules', ['outlet_id' => $branch, 'product_id' => $product]);
    }

    public function test_daily_email_is_sent_once_per_recipient_and_only_for_low_stock(): void
    {
        extract($this->setupBusiness());
        Mail::fake();
        $this->actingAs($owner)->put('/stock-alerts/rules/'.$product, ['minimum_quantity' => 3, 'target_quantity' => 10])->assertRedirect();
        $this->put('/stock-alerts/email', ['email_enabled' => 1, 'recipients' => "OWNER@example.com\nmanager@example.com\nowner@example.com"])->assertRedirect();
        $this->assertDatabaseHas('stock_alert_settings', ['outlet_id' => $outlet, 'email_enabled' => 1]);
        Artisan::call('stock:alert-low');
        Artisan::call('stock:alert-low');
        Mail::assertSent(LowStockDigest::class, 2);
        Mail::assertSent(LowStockDigest::class, fn (LowStockDigest $mail) => $mail->hasTo('owner@example.com')
            && $mail->outletName === 'Pusat' && $mail->products->first()->suggested_quantity === 8);
        $this->assertDatabaseCount('stock_alert_deliveries', 2);

        $this->put('/stock-alerts/email', ['email_enabled' => 0, 'recipients' => 'owner@example.com'])->assertRedirect();
        Artisan::call('stock:alert-low');
        Mail::assertSent(LowStockDigest::class, 2);
    }

    public function test_invalid_rules_and_email_settings_are_rejected_and_cashier_cannot_manage(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->put('/stock-alerts/rules/'.$product, ['minimum_quantity' => 5, 'target_quantity' => 5])->assertSessionHasErrors('target_quantity');
        $this->put('/stock-alerts/email', ['email_enabled' => 1, 'recipients' => ''])->assertSessionHasErrors('recipients');
        $this->put('/stock-alerts/email', ['email_enabled' => 1, 'recipients' => 'bukan-email'])->assertSessionHasErrors('recipients');
        $cashier = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'cashier']);
        $this->actingAs($cashier)->get('/stock-alerts')->assertForbidden();
        $this->put('/stock-alerts/rules/'.$product, ['minimum_quantity' => 3, 'target_quantity' => 10])->assertForbidden();

        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherOutlet = DB::table('outlets')->insertGetId(['business_id' => $otherBusiness, 'name' => 'Lain', 'code' => 'L', 'created_at' => now(), 'updated_at' => now()]);
        $otherOwner = User::factory()->create(['business_id' => $otherBusiness, 'outlet_id' => $otherOutlet, 'role' => 'owner']);
        $this->actingAs($otherOwner)->put('/stock-alerts/rules/'.$product, ['minimum_quantity' => 3, 'target_quantity' => 10])->assertNotFound();
    }
}
