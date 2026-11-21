<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiOutletTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_outlet_and_assigned_user_can_switch_without_mixing_stock(): void
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko Satu', 'created_at' => now(), 'updated_at' => now()]);
        $main = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Utama', 'code' => 'UTAMA', 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $main, 'role' => 'owner']);

        $this->actingAs($owner)->post('/outlets', ['name' => 'Cabang Dua', 'code' => 'CABANG-02'])->assertRedirect('/outlets');
        $branch = DB::table('outlets')->where('business_id', $business)->where('code', 'CABANG-02')->value('id');
        $this->get('/outlets')->assertOk()->assertSee('Cabang Dua');

        $this->post('/users', [
            'name' => 'Kasir Dua', 'email' => 'multi@example.test', 'role' => 'cashier',
            'outlet_id' => $main, 'outlet_ids' => [$main, $branch],
            'password' => 'KataSandi123', 'password_confirmation' => 'KataSandi123',
        ])->assertRedirect('/users');
        $cashier = User::where('email', 'multi@example.test')->firstOrFail();
        $this->assertDatabaseCount('user_outlets', 3);

        $product = DB::table('products')->insertGetId(['business_id' => $business, 'sku' => 'A1', 'name' => 'Sabun', 'price' => 10000, 'point_eligible' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->insert([
            ['outlet_id' => $main, 'product_id' => $product, 'quantity' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['outlet_id' => $branch, 'product_id' => $product, 'quantity' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($cashier)->get('/checkout')->assertOk()->assertSee('>4</td>', false);
        $this->post('/outlet/switch', ['outlet_id' => $branch])->assertRedirect('/');
        $this->get('/checkout')->assertOk()->assertSee('>9</td>', false);
        $this->get('/products')->assertOk()->assertSee('9 tersedia');
        $this->assertSame($branch, session('active_outlet_id'));
    }

    public function test_user_cannot_switch_to_unassigned_or_foreign_outlet(): void
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Toko Satu', 'created_at' => now(), 'updated_at' => now()]);
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Toko Dua', 'created_at' => now(), 'updated_at' => now()]);
        $main = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Utama', 'code' => 'UTAMA', 'created_at' => now(), 'updated_at' => now()]);
        $unassigned = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Cabang', 'code' => 'CABANG', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('outlets')->insertGetId(['business_id' => $otherBusiness, 'name' => 'Asing', 'code' => 'ASING', 'created_at' => now(), 'updated_at' => now()]);
        $cashier = User::factory()->create(['business_id' => $business, 'outlet_id' => $main, 'role' => 'cashier']);

        $this->actingAs($cashier)->post('/outlet/switch', ['outlet_id' => $unassigned])->assertSessionHasErrors('outlet_id');
        $this->post('/outlet/switch', ['outlet_id' => $foreign])->assertSessionHasErrors('outlet_id');
        $this->assertNull(session('active_outlet_id'));
        $this->get('/outlets')->assertForbidden();
    }
}
