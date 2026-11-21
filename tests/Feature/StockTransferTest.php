<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko', 'created_at' => now(), 'updated_at' => now()]);
        $source = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Pusat', 'code' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        $destination = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Cabang', 'code' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'A1', 'name' => 'Sabun', 'price' => 10000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->insert(['outlet_id' => $source, 'product_id' => $product, 'quantity' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $source, 'role' => 'owner']);
        return compact('business', 'source', 'destination', 'product', 'owner');
    }

    private function send(int $destination, int $product, int $quantity = 4): void
    {
        $this->post('/transfers', ['destination_outlet_id' => $destination, 'items' => [['product_id' => $product, 'quantity' => $quantity]]])
            ->assertRedirect();
    }

    public function test_stock_moves_only_after_destination_confirms_and_cannot_be_received_twice(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->get('/transfers')->assertOk()->assertSee('Transfer stok');
        $this->send($destination, $product);
        $transfer = DB::table('stock_transfers')->first();
        $this->assertSame('in_transit', $transfer->status);
        $this->assertDatabaseHas('stocks', ['outlet_id' => $source, 'product_id' => $product, 'quantity' => 6]);
        $this->assertDatabaseMissing('stocks', ['outlet_id' => $destination, 'product_id' => $product]);
        $this->post("/transfers/{$transfer->id}/receive")->assertForbidden();
        $this->post('/outlet/switch', ['outlet_id' => $destination])->assertRedirect();
        $this->get("/transfers/{$transfer->id}")->assertOk()->assertSee('Konfirmasi penerimaan');
        $this->post("/transfers/{$transfer->id}/receive")->assertRedirect();
        $this->assertDatabaseHas('stocks', ['outlet_id' => $destination, 'product_id' => $product, 'quantity' => 4]);
        $this->post("/transfers/{$transfer->id}/receive")->assertSessionHasErrors('transfer');
        $this->assertDatabaseHas('stocks', ['outlet_id' => $destination, 'product_id' => $product, 'quantity' => 4]);
        $this->assertDatabaseHas('stock_movements', ['stock_transfer_id' => $transfer->id, 'outlet_id' => $source, 'delta' => -4, 'reason' => 'transfer_sent']);
        $this->assertDatabaseHas('stock_movements', ['stock_transfer_id' => $transfer->id, 'outlet_id' => $destination, 'delta' => 4, 'reason' => 'transfer_received']);
    }

    public function test_cancel_restores_source_once_and_insufficient_stock_is_rejected(): void
    {
        extract($this->setupBusiness());
        $this->actingAs($owner)->post('/transfers', ['destination_outlet_id' => $destination, 'items' => [['product_id' => $product, 'quantity' => 11]]])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->send($destination, $product);
        $id = DB::table('stock_transfers')->value('id');
        $this->post("/transfers/{$id}/cancel")->assertRedirect();
        $this->assertDatabaseHas('stocks', ['outlet_id' => $source, 'product_id' => $product, 'quantity' => 10]);
        $this->post("/transfers/{$id}/cancel")->assertSessionHasErrors('transfer');
        $this->assertDatabaseHas('stocks', ['outlet_id' => $source, 'product_id' => $product, 'quantity' => 10]);
    }

    public function test_access_is_limited_to_assigned_outlets_and_business(): void
    {
        extract($this->setupBusiness());
        $cashier = User::factory()->create(['business_id' => $business, 'outlet_id' => $source, 'role' => 'cashier']);
        $this->actingAs($cashier)->get('/transfers')->assertForbidden();
        $this->post('/transfers', ['destination_outlet_id' => $destination, 'items' => [['product_id' => $product, 'quantity' => 1]]])->assertForbidden();

        $this->actingAs($owner);
        $this->send($destination, $product);
        $id = DB::table('stock_transfers')->value('id');
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherOutlet = DB::table('outlets')->insertGetId(['business_id' => $otherBusiness, 'name' => 'Lain', 'code' => 'L', 'created_at' => now(), 'updated_at' => now()]);
        $otherOwner = User::factory()->create(['business_id' => $otherBusiness, 'outlet_id' => $otherOutlet, 'role' => 'owner']);
        $this->actingAs($otherOwner)->get("/transfers/{$id}")->assertNotFound();
        $this->post('/transfers', ['destination_outlet_id' => $destination, 'items' => [['product_id' => $product, 'quantity' => 1]]])->assertSessionHasErrors('destination_outlet_id');
    }
}
