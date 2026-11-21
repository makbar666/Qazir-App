<?php

namespace App\Http\Controllers;

use App\Support\RoleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()->role === 'owner', 403);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $business = $request->user()->business_id;
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'key' => [$id ? 'prohibited' : 'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'key')->where('business_id', $business)],
            'menus' => ['required', 'array', 'min:1'],
            'menus.*' => ['required', 'string', 'distinct', Rule::in(array_keys(RoleAccess::MENUS))],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(array_keys(RoleAccess::ABILITIES))],
        ]);
    }

    public function index(Request $request): View
    {
        $this->authorizeOwner($request);
        RoleAccess::seedDefaults($request->user()->business_id);

        return view('roles', [
            'roles' => DB::table('roles')->where('business_id', $request->user()->business_id)->orderByDesc('is_system')->orderBy('name')->get(),
            'menuOptions' => RoleAccess::MENUS,
            'abilityOptions' => RoleAccess::ABILITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOwner($request);
        RoleAccess::seedDefaults($request->user()->business_id);
        $data = $this->validated($request);
        DB::table('roles')->insert([
            'business_id' => $request->user()->business_id,
            'key' => $data['key'], 'name' => $data['name'],
            'menus' => json_encode($data['menus']),
            'abilities' => json_encode($data['abilities'] ?? []),
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('roles')->with('success', 'Peran berhasil ditambahkan.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->authorizeOwner($request);
        $role = DB::table('roles')->where('business_id', $request->user()->business_id)->where('id', $id)->first();
        abort_unless($role, 404);
        abort_if($role->key === 'owner', 403, 'Akses Owner tidak dapat dibatasi.');
        $data = $this->validated($request, $id);

        DB::table('roles')->where('id', $id)->update([
            'name' => $data['name'], 'menus' => json_encode($data['menus']),
            'abilities' => json_encode($data['abilities'] ?? []), 'updated_at' => now(),
        ]);

        return redirect()->route('roles')->with('success', 'Peran berhasil diperbarui.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizeOwner($request);
        $role = DB::table('roles')->where('business_id', $request->user()->business_id)->where('id', $id)->first();
        abort_unless($role, 404);
        if ($role->is_system) throw ValidationException::withMessages(['role' => 'Peran bawaan tidak dapat dihapus.']);
        if (DB::table('users')->where('business_id', $request->user()->business_id)->where('role', $role->key)->exists()) {
            throw ValidationException::withMessages(['role' => 'Pindahkan pengguna ke peran lain sebelum menghapus peran ini.']);
        }
        DB::table('roles')->where('id', $id)->delete();

        return redirect()->route('roles')->with('success', 'Peran berhasil dihapus.');
    }
}
