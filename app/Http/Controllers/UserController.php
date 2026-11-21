<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RoleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()->role === 'owner', 403);
    }

    private function rules(Request $request, ?User $user = null): array
    {
        RoleAccess::seedDefaults($request->user()->business_id);
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::exists('roles', 'key')->where('business_id', $request->user()->business_id)],
            'outlet_id' => ['required', 'integer', Rule::exists('outlets', 'id')->where('business_id', $request->user()->business_id)],
            'outlet_ids' => ['required', 'array', 'min:1'],
            'outlet_ids.*' => ['required', 'integer', 'distinct', Rule::exists('outlets', 'id')->where('business_id', $request->user()->business_id)],
            'is_active' => [$user ? 'required' : 'sometimes', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ];
    }

    public function index(Request $request): View
    {
        $this->authorizeOwner($request);
        RoleAccess::seedDefaults($request->user()->business_id);
        $users = DB::table('users as u')
            ->leftJoin('outlets as o', 'o.id', '=', 'u.outlet_id')
            ->where('u.business_id', $request->user()->business_id)
            ->select('u.id', 'u.name', 'u.email', 'u.role', 'u.outlet_id', 'u.is_active', 'o.name as outlet_name')
            ->orderBy('u.name')->paginate(20);

        return view('users', [
            'users' => $users,
            'outlets' => DB::table('outlets')->where('business_id', $request->user()->business_id)->orderBy('name')->get(),
            'roles' => DB::table('roles')->where('business_id', $request->user()->business_id)->orderByDesc('is_system')->orderBy('name')->get(['key', 'name']),
            'assignments' => DB::table('user_outlets')->whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('outlet_id')->all()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate($this->rules($request));
        $this->validateDefaultOutlet($data);

        DB::transaction(function () use ($request, $data) {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->role = $data['role'];
            $user->outlet_id = $data['outlet_id'];
            $user->business_id = $request->user()->business_id;
            $user->is_active = true;
            $user->password = $data['password'];
            $user->save();
            $this->syncOutlets($user, $data['outlet_ids']);
        });

        return redirect()->route('users')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->authorizeOwner($request);
        $user = User::where('business_id', $request->user()->business_id)->findOrFail($id);
        $data = $request->validate($this->rules($request, $user));
        $this->validateDefaultOutlet($data);

        DB::transaction(function () use ($request, $id, $data) {
            $user = User::where('business_id', $request->user()->business_id)->lockForUpdate()->findOrFail($id);

            if ($user->id === $request->user()->id && ($data['role'] !== $user->role || !$data['is_active'])) {
                throw ValidationException::withMessages(['role' => 'Peran atau status akun sendiri tidak dapat diubah.']);
            }

            if ($user->role === 'owner' && $user->is_active && ($data['role'] !== 'owner' || !$data['is_active'])) {
                $activeOwners = User::where('business_id', $user->business_id)->where('role', 'owner')->where('is_active', true)->count();
                if ($activeOwners <= 1) {
                    throw ValidationException::withMessages(['role' => 'Bisnis harus memiliki setidaknya satu owner aktif.']);
                }
            }

            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->role = $data['role'];
            $user->outlet_id = $data['outlet_id'];
            $user->is_active = (bool) $data['is_active'];
            if (!empty($data['password'])) $user->password = $data['password'];
            $user->save();
            $this->syncOutlets($user, $data['outlet_ids']);
        });

        return redirect()->route('users')->with('success', 'Pengguna berhasil diperbarui.');
    }

    private function validateDefaultOutlet(array $data): void
    {
        if (!in_array((int) $data['outlet_id'], array_map('intval', $data['outlet_ids']), true)) {
            throw ValidationException::withMessages(['outlet_id' => 'Outlet utama harus termasuk dalam outlet yang diizinkan.']);
        }
    }

    private function syncOutlets(User $user, array $ids): void
    {
        DB::table('user_outlets')->where('user_id', $user->id)->delete();
        DB::table('user_outlets')->insert(array_map(fn ($id) => ['user_id' => $user->id, 'outlet_id' => (int) $id], $ids));
    }
}
