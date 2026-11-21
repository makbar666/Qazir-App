<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashShiftTest extends TestCase
{
    use RefreshDatabase;

    private function setupOutlet(): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko Shift', 'created_at' => now(), 'updated_at' => now()]);
        $outlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Utama', 'code' => 'UTAMA', 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $product = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'A1', 'name' => 'Sabun', 'price' => 10000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->insert(['outlet_id' => $outlet, 'product_id' => $product, 'quantity' => 10, 'created_at' => now(), 'updated_at' => now()]);
        return [$owner, $outlet, $product];
    }

    private function sale(int $product, string $method = 'cash'): array
    {
        return ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product, 'quantity' => 1]], 'discount' => 1000, 'payment_method' => $method, 'amount_received' => 15000];
    }

    public function test_cash_shift_reconciles_sales_manual_movements_and_refund_on_new_shift(): void
    {
        [$owner, $outlet, $product] = $this->setupOutlet();
        $this->actingAs($owner)->get('/shifts')->assertOk()->assertSee('Buka shift baru');
        $this->post('/shifts', ['opening_cash' => 50000])->assertRedirect();
        $first = DB::table('cash_shifts')->first();
        $this->post('/shifts', ['opening_cash' => 1000])->assertSessionHasErrors('shift');

        $payload = $this->sale($product);
        $this->post('/checkout', $payload)->assertRedirect();
        $this->post('/checkout', $payload)->assertRedirect();
        $sale = DB::table('sales')->first();
        $this->assertSame($first->id, $sale->cash_shift_id);
        $this->assertDatabaseHas('cash_shift_entries', ['cash_shift_id' => $first->id, 'sale_id' => $sale->id, 'type' => 'sale', 'delta' => 9000]);
        $this->assertDatabaseCount('cash_shift_entries', 1);

        $this->post('/shifts/'.$first->id.'/entries', ['type' => 'pay_in', 'amount' => 5000, 'note' => 'Tambah kembalian'])->assertRedirect();
        $this->post('/shifts/'.$first->id.'/entries', ['type' => 'pay_out', 'amount' => 2000, 'note' => 'Ambil uang'])->assertRedirect();
        $this->get('/shifts/'.$first->id)->assertOk()->assertSee('Rp62.000');
        $this->post('/shifts/'.$first->id.'/close', ['actual_cash' => 61000, 'closing_note' => 'Kurang seribu'])->assertRedirect();
        $this->assertDatabaseHas('cash_shifts', ['id' => $first->id, 'status' => 'closed', 'expected_cash' => 62000, 'actual_cash' => 61000, 'difference' => -1000]);
        $this->post('/shifts/'.$first->id.'/close', ['actual_cash' => 61000])->assertSessionHasErrors('shift');
        $this->post('/checkout', $this->sale($product))->assertSessionHasErrors('payment_method');

        $this->post('/shifts', ['opening_cash' => 20000])->assertRedirect();
        $second = DB::table('cash_shifts')->orderByDesc('id')->first();
        $this->post('/sales/'.$sale->id.'/refund', ['reason' => 'Barang dikembalikan'])->assertRedirect();
        $this->assertDatabaseHas('cash_shift_entries', ['cash_shift_id' => $second->id, 'sale_id' => $sale->id, 'type' => 'refund', 'delta' => -9000]);
        $this->post('/shifts/'.$second->id.'/close', ['actual_cash' => 11000])->assertRedirect();
        $this->assertDatabaseHas('cash_shifts', ['id' => $first->id, 'expected_cash' => 62000, 'difference' => -1000]);
        $this->assertDatabaseHas('cash_shifts', ['id' => $second->id, 'expected_cash' => 11000, 'difference' => 0]);
    }

    public function test_other_cashier_cannot_manage_shift_and_qris_sale_does_not_require_one(): void
    {
        [$owner, $outlet, $product] = $this->setupOutlet();
        $cashier = User::factory()->create(['business_id' => $owner->business_id, 'outlet_id' => $outlet, 'role' => 'cashier']);
        $this->actingAs($owner)->post('/shifts', ['opening_cash' => 10000])->assertRedirect();
        $shift = DB::table('cash_shifts')->first();
        $this->actingAs($cashier)->get('/shifts/'.$shift->id)->assertNotFound();
        $this->post('/shifts/'.$shift->id.'/close', ['actual_cash' => 10000])->assertNotFound();
        $this->post('/checkout', $this->sale($product))->assertSessionHasErrors('payment_method');
        $this->post('/checkout', $this->sale($product, 'card'))->assertSessionHasErrors('payment_method');
        $this->post('/checkout', $this->sale($product, 'transfer'))->assertSessionHasErrors('payment_method');
        $this->post('/checkout', $this->sale($product, 'qris'))->assertRedirect();
        $this->assertDatabaseHas('sales', ['payment_method' => 'qris', 'cash_shift_id' => null]);
        $this->actingAs($owner)->post('/shifts/'.$shift->id.'/close', ['actual_cash' => 10000])->assertRedirect();
    }
}
