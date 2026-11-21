<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $name): array
    {
        $business = DB::table('businesses')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        $outlet = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => 'Utama', 'code' => 'UTAMA', 'created_at' => now(), 'updated_at' => now()]);

        return [$business, $outlet];
    }

    private function payload(int $outlet, array $changes = []): array
    {
        return array_merge([
            'name' => 'Kasir Baru',
            'email' => 'kasir@example.test',
            'role' => 'cashier',
            'outlet_id' => $outlet,
            'outlet_ids' => [$outlet],
            'password' => 'KataSandi123',
            'password_confirmation' => 'KataSandi123',
        ], $changes);
    }

    public function test_owner_can_create_edit_and_deactivate_user(): void
    {
        [$business, $outlet] = $this->business('Toko Satu');
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);

        $this->actingAs($owner)->get('/users')->assertOk()->assertSee('Tambah pengguna');
        $this->post('/users', $this->payload($outlet))->assertRedirect('/users');
        $created = User::where('email', 'kasir@example.test')->firstOrFail();
        $this->assertSame($business, $created->business_id);
        $this->assertTrue(Hash::check('KataSandi123', $created->password));

        $this->put('/users/'.$created->id, $this->payload($outlet, [
            'name' => 'Manager Toko', 'role' => 'manager', 'is_active' => '0',
            'password' => '', 'password_confirmation' => '',
        ]))->assertRedirect('/users');
        $this->assertDatabaseHas('users', ['id' => $created->id, 'name' => 'Manager Toko', 'role' => 'manager', 'is_active' => false]);
        $this->assertTrue(Hash::check('KataSandi123', $created->fresh()->password));

        $this->post('/logout');
        $this->post('/login', ['email' => 'kasir@example.test', 'password' => 'KataSandi123'])->assertSessionHasErrors('email');
    }

    public function test_user_management_is_owner_only_and_scoped_to_business(): void
    {
        [$business, $outlet] = $this->business('Toko Satu');
        [$otherBusiness, $otherOutlet] = $this->business('Toko Dua');
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $manager = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'manager']);
        $other = User::factory()->create(['business_id' => $otherBusiness, 'outlet_id' => $otherOutlet, 'role' => 'cashier']);

        $this->actingAs($manager)->get('/users')->assertForbidden();
        $this->post('/users', $this->payload($outlet))->assertForbidden();
        $this->put('/users/'.$other->id, $this->payload($outlet, ['is_active' => '0']))->assertForbidden();

        $this->actingAs($owner)->get('/users')->assertOk()->assertDontSee($other->email);
        $this->post('/users', $this->payload($otherOutlet))->assertSessionHasErrors('outlet_id');
        $this->put('/users/'.$other->id, $this->payload($outlet, ['is_active' => '0']))->assertNotFound();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_owner_cannot_disable_or_demote_own_account_and_active_session_is_revoked(): void
    {
        [$business, $outlet] = $this->business('Toko Satu');
        $owner = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'owner']);
        $cashier = User::factory()->create(['business_id' => $business, 'outlet_id' => $outlet, 'role' => 'cashier']);

        $this->actingAs($owner)->put('/users/'.$owner->id, $this->payload($outlet, [
            'email' => $owner->email, 'role' => 'cashier', 'is_active' => '0', 'password' => '', 'password_confirmation' => '',
        ]))->assertSessionHasErrors('role');
        $this->assertSame('owner', $owner->fresh()->role);
        $this->assertTrue($owner->fresh()->is_active);

        $cashier->is_active = false;
        $cashier->save();
        $this->actingAs($cashier)->get('/')->assertRedirect('/login');
    }
}
