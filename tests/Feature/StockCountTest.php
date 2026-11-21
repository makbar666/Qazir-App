<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko', 'created_at' => now(), 'updated_at' => now()]);
        $outlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Pusat', 'code' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Cabang', 'code' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'A1', 'name' => 'Sabun', 'price' => 10000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $second = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'A2', 'name' => 'Sampo', 'price' => 15000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->insert(['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $cashier = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'cashier']);
        return compact('business', 'outlet', 'branch', 'product', 'second', 'owner', 'cashier');
    }

    public function test_cashier_counts_and_owner_approves_only_after_all_products_are_counted(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($cashier)->get('/stock-counts')->assertOk()->assertSee('Stok opname');
        $this->post('/stock-counts', ['note' => 'Hitung pagi'])->assertRedirect();
        $id = DB::table('stock_counts')->value('id');
        $this->post('/stock-counts')->assertSessionHasErrors('count');
        $this->get("/stock-counts/{$id}")->assertOk()->assertSee('Simpan hasil hitung');
        $this->put("/stock-counts/{$id}", ['counts' => [$product => 8]])->assertRedirect();
        $this->post("/stock-counts/{$id}/submit")->assertSessionHasErrors('count');
        $this->put("/stock-counts/{$id}", ['counts' => [$second => 3]])->assertRedirect();
        $this->post("/stock-counts/{$id}/submit")->assertRedirect();
        $this->post("/stock-counts/{$id}/approve")->assertForbidden();
        $this->assertDatabaseHas('stocks', ['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 10]);

        $this->actingAs($owner)->get("/stock-counts/{$id}")->assertOk()->assertSee('Setujui koreksi stok');
        $this->post("/stock-counts/{$id}/approve")->assertRedirect();
        $this->assertDatabaseHas('stocks', ['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 8]);
        $this->assertDatabaseHas('stocks', ['outlet_id' => $outlet, 'product_id' => $second, 'quantity' => 3]);
        $this->assertDatabaseHas('stock_movements', ['stock_count_id' => $id, 'product_id' => $product, 'delta' => -2, 'reason' => 'stock_count']);
        $this->assertDatabaseHas('stock_movements', ['stock_count_id' => $id, 'product_id' => $second, 'delta' => 3, 'reason' => 'stock_count']);
        $this->post("/stock-counts/{$id}/approve")->assertSessionHasErrors('count');
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_approval_rejects_stale_snapshot_after_stock_movement(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->post('/stock-counts')->assertRedirect();
        $id = DB::table('stock_counts')->value('id');
        $this->put("/stock-counts/{$id}", ['counts' => [$product => 9, $second => 0]])->assertRedirect();
        $this->post("/stock-counts/{$id}/submit")->assertRedirect();
        DB::table('stocks')->where('outlet_id', $outlet)->where('product_id', $product)->decrement('quantity');
        DB::table('stock_movements')->insert(['business_id' => $business, 'outlet_id' => $outlet, 'product_id' => $product, 'actor_id' => $owner->id, 'delta' => -1, 'reason' => 'sale', 'created_at' => now(), 'updated_at' => now()]);
        $this->post("/stock-counts/{$id}/approve")->assertSessionHasErrors('count');
        $this->assertDatabaseHas('stocks', ['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 9]);
        $this->assertDatabaseHas('stock_counts', ['id' => $id, 'status' => 'submitted']);
        $this->post("/stock-counts/{$id}/cancel")->assertRedirect();
        $this->assertDatabaseHas('stock_counts', ['id' => $id, 'status' => 'cancelled']);
        $this->post('/stock-counts')->assertRedirect();
        $this->assertDatabaseCount('stock_counts', 2);
    }

    public function test_counts_are_scoped_to_active_outlet_and_business(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->post('/stock-counts')->assertRedirect();
        $id = DB::table('stock_counts')->value('id');
        $this->post('/outlet/switch', ['outlet_id' => $branch])->assertRedirect();
        $this->get("/stock-counts/{$id}")->assertNotFound();
        $this->put("/stock-counts/{$id}", ['counts' => [$product => 1]])->assertNotFound();
        $this->post("/stock-counts/{$id}/approve")->assertNotFound();
        $this->get('/stock-counts')->assertOk()->assertDontSee('OPN-000001');
        $this->post('/stock-counts')->assertRedirect();
        $this->assertDatabaseCount('stock_counts', 2);

        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherOutlet = DB::table('outlets')->insertGetId(['business_id' => $otherBusiness, 'name' => 'Lain', 'code' => 'L', 'created_at' => now(), 'updated_at' => now()]);
        $otherOwner = User::factory()->create(['business_id' => $otherBusiness, 'outlet_id' => $otherOutlet, 'role' => 'owner']);
        $this->actingAs($otherOwner)->get("/stock-counts/{$id}")->assertNotFound();
    }

    public function test_invalid_product_or_negative_quantity_is_rejected(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($cashier)->post('/stock-counts')->assertRedirect();
        $id = DB::table('stock_counts')->value('id');
        $this->put("/stock-counts/{$id}", ['counts' => [$product => -1]])->assertSessionHasErrors('counts.'.$product);
        $this->put("/stock-counts/{$id}", ['counts' => [9999 => 1]])->assertSessionHasErrors('counts');
        $this->assertDatabaseHas('stock_count_items', ['stock_count_id' => $id, 'product_id' => $product, 'counted_quantity' => null]);
    }
}
