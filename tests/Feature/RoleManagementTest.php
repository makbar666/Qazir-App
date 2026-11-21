<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $name): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        $outlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Utama', 'code' => 'UTAMA', 'created_at' => now(), 'updated_at' => now()]);
        return [$business, $outlet];
    }

    public function test_custom_role_controls_visible_menu_and_direct_route_access(): void
    {
        [$business, $outlet] = $this->business('Toko Satu');
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $this->actingAs($owner)->post('/roles', [
            'key' => 'supervisor', 'name' => 'Supervisor', 'menus' => ['checkout', 'sales'],
        ])->assertRedirect('/roles');
        $roleId = DB::table('roles')->where('business_id', $business)->where('key', 'supervisor')->value('id');

        $this->post('/users', [
            'name' => 'Supervisor Satu', 'email' => 'supervisor@example.test', 'role' => 'supervisor',
            'outlet_id' => $outlet, 'outlet_ids' => [$outlet],
            'password' => 'KataSandi123', 'password_confirmation' => 'KataSandi123',
        ])->assertRedirect('/users');
        $supervisor = User::where('email', 'supervisor@example.test')->firstOrFail();

        $this->actingAs($supervisor)->get('/checkout')->assertOk()->assertSee('Kasir')->assertDontSee('Produk & stok');
        $this->get('/')->assertForbidden();
        $this->get('/products')->assertForbidden();
        $this->get('/roles')->assertForbidden();

        $this->actingAs($owner)->put('/roles/'.$roleId, [
            'name' => 'Supervisor', 'menus' => ['dashboard', 'products'], 'abilities' => ['products_manage'],
        ])->assertRedirect('/roles');
        $this->actingAs($supervisor)->get('/products')->assertOk()->assertSee('Tambah produk');
        $this->get('/checkout')->assertForbidden();
        $this->get('/')->assertOk()->assertDontSee('Kasir</a>', false);
    }

    public function test_role_master_is_owner_only_and_owner_role_is_protected(): void
    {
        [$business, $outlet] = $this->business('Toko Satu');
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $manager = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'manager']);
        $this->actingAs($owner)->get('/roles')->assertOk()->assertSee('Master Peran');
        $ownerRole = DB::table('roles')->where('business_id', $business)->where('key', 'owner')->first();

        $this->put('/roles/'.$ownerRole->id, ['name' => 'Owner Terbatas', 'menus' => ['checkout']])->assertForbidden();
        $this->delete('/roles/'.$ownerRole->id)->assertSessionHasErrors('role');
        $this->assertSame('Owner', DB::table('roles')->where('id', $ownerRole->id)->value('name'));

        $this->actingAs($manager)->get('/roles')->assertForbidden();
        $this->post('/roles', ['key' => 'operator', 'name' => 'Operator', 'menus' => ['checkout']])->assertForbidden();
    }
}
