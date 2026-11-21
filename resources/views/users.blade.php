@extends('layout')
@section('title','Pengguna')
@section('content')
<div x-data>
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Pengelolaan</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">Pengguna</h1>
            <p class="mt-2 text-sm text-[#718075]">Atur akses pengguna ke satu atau beberapa outlet.</p>
        </div>
        <button type="button" class="btn btn-primary" @click="$refs.addUserDialog.showModal()">+ Tambah pengguna</button>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Pengguna</th><th>Peran</th><th>Outlet</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td class="font-semibold">{{ $user->name }}@if($user->id === auth()->id()) <span class="text-xs font-normal text-[#718075]">(Anda)</span>@endif<div class="text-xs font-normal text-[#8b978d]">{{ $user->email }}</div></td>
                            <td>{{ $roles->firstWhere('key', $user->role)->name ?? $user->role }}</td>
                            <td><div>{{ $user->role === 'owner' ? 'Semua outlet' : $outlets->whereIn('id', $assignments[$user->id] ?? [])->pluck('name')->join(', ') }}</div><div class="text-xs text-[#8b978d]">Utama: {{ $user->outlet_name ?? '—' }}</div></td>
                            <td><span class="inline-flex rounded-full px-3 py-1 text-xs {{ $user->is_active ? 'bg-[#edf5e9] text-[#315e3d]' : 'bg-red-50 text-red-700' }}">{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td><button type="button" class="btn btn-light" @click="$refs.editUser{{ $user->id }}.showModal()">Edit</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-[#89958a]">Belum ada pengguna.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($users->hasPages())<div class="mt-5">{{ $users->links() }}</div>@endif

    <dialog x-ref="addUserDialog" x-init="{{ old('form_context') === 'add_user' ? '$el.showModal()' : '' }}" @click.self="$el.close()" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50">
        <div class="flex items-start justify-between border-b border-[#edf0eb] p-6">
            <div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">Akun baru</p><h2 class="mt-2 text-xl font-semibold">Tambah pengguna</h2></div>
            <button type="button" class="text-2xl leading-none text-[#718075]" aria-label="Tutup modal" @click="$refs.addUserDialog.close()">×</button>
        </div>
        <form method="post" action="{{ route('users.store') }}" class="space-y-4 p-6">
            @csrf<input type="hidden" name="form_context" value="add_user">
            @if(old('form_context') === 'add_user' && $errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <div><label class="label" for="add-name">Nama</label><input id="add-name" class="field" name="name" value="{{ old('form_context') === 'add_user' ? old('name') : '' }}" maxlength="120" required></div>
            <div><label class="label" for="add-email">Email</label><input id="add-email" class="field" type="email" name="email" value="{{ old('form_context') === 'add_user' ? old('email') : '' }}" required></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="add-role">Peran</label><select id="add-role" class="field" name="role" required>@foreach($roles as $role)<option value="{{ $role->key }}" @selected((old('form_context') === 'add_user' ? old('role') : 'cashier') === $role->key)>{{ $role->name }}</option>@endforeach</select></div>
                <div><label class="label" for="add-outlet">Outlet utama</label><select id="add-outlet" class="field" name="outlet_id" required>@foreach($outlets as $outlet)<option value="{{ $outlet->id }}" @selected(old('form_context') === 'add_user' && old('outlet_id') == $outlet->id)>{{ $outlet->name }}</option>@endforeach</select></div>
            </div>
            <fieldset><legend class="label">Outlet yang diizinkan</legend><div class="grid max-h-36 gap-2 overflow-y-auto rounded-xl border border-[#d9ded7] p-3 sm:grid-cols-2">@foreach($outlets as $outlet)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="outlet_ids[]" value="{{ $outlet->id }}" @checked(old('form_context') === 'add_user' ? in_array($outlet->id, old('outlet_ids', [])) : $loop->first)> {{ $outlet->name }}</label>@endforeach</div><p class="mt-1 text-xs text-[#718075]">Pilih outlet utama juga. Owner tetap dapat mengakses semua outlet.</p></fieldset>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="add-password">Kata sandi</label><input id="add-password" class="field" type="password" name="password" minlength="8" autocomplete="new-password" required></div>
                <div><label class="label" for="add-confirmation">Ulangi kata sandi</label><input id="add-confirmation" class="field" type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required></div>
            </div>
            <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-light" @click="$refs.addUserDialog.close()">Batal</button><button class="btn btn-primary">Simpan pengguna</button></div>
        </form>
    </dialog>

    @foreach($users as $user)
        <dialog x-ref="editUser{{ $user->id }}" x-init="{{ old('form_context') === 'edit_user_'.$user->id ? '$el.showModal()' : '' }}" @click.self="$el.close()" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50">
            <div class="flex items-start justify-between border-b border-[#edf0eb] p-6">
                <div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">Kelola akun</p><h2 class="mt-2 text-xl font-semibold">Edit pengguna</h2></div>
                <button type="button" class="text-2xl leading-none text-[#718075]" aria-label="Tutup modal" @click="$refs.editUser{{ $user->id }}.close()">×</button>
            </div>
            <form method="post" action="{{ route('users.update',$user->id) }}" class="space-y-4 p-6">
                @csrf @method('PUT')<input type="hidden" name="form_context" value="edit_user_{{ $user->id }}">
                @if(old('form_context') === 'edit_user_'.$user->id && $errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
                <div><label class="label" for="name-{{ $user->id }}">Nama</label><input id="name-{{ $user->id }}" class="field" name="name" value="{{ old('form_context') === 'edit_user_'.$user->id ? old('name') : $user->name }}" maxlength="120" required></div>
                <div><label class="label" for="email-{{ $user->id }}">Email</label><input id="email-{{ $user->id }}" class="field" type="email" name="email" value="{{ old('form_context') === 'edit_user_'.$user->id ? old('email') : $user->email }}" required></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="role-{{ $user->id }}">Peran</label><select id="role-{{ $user->id }}" class="field" name="role" @disabled($user->id === auth()->id()) required>@foreach($roles as $role)<option value="{{ $role->key }}" @selected((old('form_context') === 'edit_user_'.$user->id ? old('role') : $user->role) === $role->key)>{{ $role->name }}</option>@endforeach</select>@if($user->id === auth()->id())<input type="hidden" name="role" value="{{ $user->role }}">@endif</div>
                    <div><label class="label" for="outlet-{{ $user->id }}">Outlet utama</label><select id="outlet-{{ $user->id }}" class="field" name="outlet_id" required>@foreach($outlets as $outlet)<option value="{{ $outlet->id }}" @selected((old('form_context') === 'edit_user_'.$user->id ? old('outlet_id') : $user->outlet_id) == $outlet->id)>{{ $outlet->name }}</option>@endforeach</select></div>
                </div>
                <fieldset><legend class="label">Outlet yang diizinkan</legend><div class="grid max-h-36 gap-2 overflow-y-auto rounded-xl border border-[#d9ded7] p-3 sm:grid-cols-2">@foreach($outlets as $outlet)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="outlet_ids[]" value="{{ $outlet->id }}" @checked(in_array($outlet->id, old('form_context') === 'edit_user_'.$user->id ? old('outlet_ids', []) : ($assignments[$user->id] ?? [])))> {{ $outlet->name }}</label>@endforeach</div><p class="mt-1 text-xs text-[#718075]">Pilih outlet utama juga. Owner tetap dapat mengakses semua outlet.</p></fieldset>
                <div><label class="label" for="status-{{ $user->id }}">Status</label><select id="status-{{ $user->id }}" class="field" name="is_active" @disabled($user->id === auth()->id()) required><option value="1" @selected((old('form_context') === 'edit_user_'.$user->id ? old('is_active') : $user->is_active) == 1)>Aktif</option><option value="0" @selected((old('form_context') === 'edit_user_'.$user->id ? old('is_active') : $user->is_active) == 0)>Nonaktif</option></select>@if($user->id === auth()->id())<input type="hidden" name="is_active" value="1"><p class="mt-1 text-xs text-[#718075]">Anda tidak dapat menonaktifkan akun sendiri.</p>@endif</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="password-{{ $user->id }}">Kata sandi baru</label><input id="password-{{ $user->id }}" class="field" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah"></div>
                    <div><label class="label" for="confirmation-{{ $user->id }}">Ulangi kata sandi</label><input id="confirmation-{{ $user->id }}" class="field" type="password" name="password_confirmation" minlength="8" autocomplete="new-password"></div>
                </div>
                <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-light" @click="$refs.editUser{{ $user->id }}.close()">Batal</button><button class="btn btn-primary">Simpan perubahan</button></div>
            </form>
        </dialog>
    @endforeach
</div>
@endsection
