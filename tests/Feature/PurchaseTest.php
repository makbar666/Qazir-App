<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko', 'created_at' => now(), 'updated_at' => now()]);
        $outlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Pusat', 'code' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Cabang', 'code' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $product = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'A1', 'name' => 'Sabun', 'price' => 10000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $supplier = DB::table('suppliers')->insertGetId(['business_id' => $business, 'name' => 'PT Pemasok', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return compact('business', 'outlet', 'branch', 'owner', 'product', 'supplier');
    }

    private function orderPayload(int $supplier, int $product, int $quantity = 5): array
    {
        return ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'items' => [['product_id' => $product, 'quantity' => $quantity, 'unit_cost' => 6500]]];
    }

    public function test_partial_receipts_add_stock_once_and_preserve_purchase_cost(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->get('/purchases/create')->assertOk()->assertSee('Pesanan pembelian baru');
        $payload = $this->orderPayload($supplier, $product);
        $this->post('/purchases', $payload)->assertRedirect();
        $this->post('/purchases', $payload)->assertRedirect();
        $this->assertDatabaseCount('purchase_orders', 1);
        $order = DB::table('purchase_orders')->first();
        $item = DB::table('purchase_order_items')->first();
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $order->id, 'quantity' => 5, 'received_quantity' => 0, 'unit_cost' => 6500]);
        $this->assertDatabaseMissing('stocks', ['outlet_id' => $outlet, 'product_id' => $product]);

        $first = ['request_key' => (string) Str::uuid(), 'reference' => 'SJ-1', 'quantities' => [$item->id => 2]];
        $this->post('/purchases/'.$order->id.'/receive', $first)->assertRedirect();
        $this->post('/purchases/'.$order->id.'/receive', $first)->assertRedirect();
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'partial']);
        $this->assertDatabaseHas('stocks', ['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 2]);
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 4]])->assertSessionHasErrors('quantities');
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 0]])->assertSessionHasErrors('quantities');
        $this->get('/purchases/'.$order->id)->assertOk()->assertSee('SJ-1')->assertSee('Rp32.500');

        DB::table('products')->where('id', $product)->update(['name' => 'Sabun Baru', 'deleted_at' => now()]);
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 3]])->assertRedirect();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'received']);
        $this->assertDatabaseHas('stocks', ['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 5]);
        $this->assertDatabaseCount('purchase_receipts', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['outlet_id' => $outlet, 'product_id' => $product, 'delta' => 3, 'reason' => 'purchase_received']);
        $this->get('/purchases/'.$order->id)->assertSee('Sabun')->assertDontSee('Sabun Baru');
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 1]])->assertSessionHasErrors('receipt');
    }

    public function test_cancelling_remaining_order_keeps_received_stock_and_scopes_by_outlet(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->post('/purchases', $this->orderPayload($supplier, $product))->assertRedirect();
        $order = DB::table('purchase_orders')->first();
        $item = DB::table('purchase_order_items')->first();
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 2]])->assertRedirect();
        $this->post('/purchases/'.$order->id.'/cancel')->assertRedirect();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('stocks', ['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 2]);
        $this->post('/purchases/'.$order->id.'/cancel')->assertSessionHasErrors('purchase');
        $this->post('/outlet/switch', ['outlet_id' => $branch])->assertRedirect();
        $this->get('/purchases/'.$order->id)->assertNotFound();
        $this->post('/purchases/'.$order->id.'/receive', ['request_key' => (string) Str::uuid(), 'quantities' => [$item->id => 1]])->assertNotFound();
        $this->assertDatabaseMissing('stocks', ['outlet_id' => $branch, 'product_id' => $product]);
    }

    public function test_supplier_and_purchase_permissions_are_business_scoped(): void
    {
        extract($this->setupBusiness());
        $cashier = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'cashier']);
        $this->actingAs($cashier)->get('/purchases')->assertForbidden();
        $this->post('/purchases', $this->orderPayload($supplier, $product))->assertForbidden();
        $this->post('/purchases/suppliers', ['name' => 'Pemasok palsu'])->assertForbidden();

        $this->actingAs($owner)->get('/purchases/suppliers')->assertOk()->assertSee('PT Pemasok');
        $this->post('/purchases/suppliers', ['name' => 'CV Baru', 'phone' => '08123456789'])->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['business_id' => $business, 'name' => 'CV Baru']);
        $this->put('/purchases/suppliers/'.$supplier, ['name' => 'PT Pemasok', 'active' => 0])->assertRedirect();
        $this->post('/purchases', $this->orderPayload($supplier, $product))->assertSessionHasErrors('supplier_id');

        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Toko lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherOutlet = DB::table('outlets')->insertGetId(['business_id' => $otherBusiness, 'name' => 'Lain', 'code' => 'L', 'created_at' => now(), 'updated_at' => now()]);
        $otherOwner = User::factory()->create(['business_id' => $otherBusiness, 'outlet_id' => $otherOutlet, 'role' => 'owner']);
        $this->actingAs($otherOwner)->post('/purchases', $this->orderPayload($supplier, $product))->assertSessionHasErrors('supplier_id');
        $this->put('/purchases/suppliers/'.$supplier, ['name' => 'Retas', 'active' => 1])->assertNotFound();
    }
}
