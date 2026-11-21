@extends('layout')
@section('title','Master Peran')
@section('content')
<div x-data>
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Pengelolaan</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Master Peran</h1><p class="mt-2 text-sm text-[#718075]">Pilih menu yang dapat dibuka oleh setiap peran. Perubahan berlaku pada permintaan berikutnya.</p></div>
        <button type="button" class="btn btn-primary" @click="$refs.addRoleDialog.showModal()">+ Tambah peran</button>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        @foreach($roles as $role)
            @php($selectedMenus = json_decode($role->menus, true) ?: [])
            @php($selectedAbilities = json_decode($role->abilities, true) ?: [])
            <div class="card p-6">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-xl font-semibold">{{ $role->name }}</h2><p class="mt-1 text-xs text-[#718075]">{{ $role->key }}{{ $role->is_system ? ' · Peran bawaan' : '' }}</p></div>@if($role->key === 'owner')<span class="rounded-full bg-[#edf5e9] px-3 py-1 text-xs text-[#315e3d]">Akses penuh</span>@endif</div>
                @if($role->key === 'owner')
                    <p class="mt-5 text-sm leading-6 text-[#718075]">Owner selalu memiliki akses penuh, termasuk Pengguna dan Master Peran, agar pengelolaan bisnis tidak terkunci.</p>
                @else
                    <form method="post" action="{{ route('roles.update',$role->id) }}" class="mt-5 space-y-5">
                        @csrf @method('PUT')<input type="hidden" name="form_context" value="edit_role_{{ $role->id }}">
                        @if(old('form_context') === 'edit_role_'.$role->id && $errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
                        <div><label class="label" for="role-name-{{ $role->id }}">Nama peran</label><input id="role-name-{{ $role->id }}" class="field" name="name" value="{{ old('form_context') === 'edit_role_'.$role->id ? old('name') : $role->name }}" maxlength="100" required></div>
                        <fieldset><legend class="label">Menu yang tampil</legend><div class="grid gap-2 sm:grid-cols-2">@foreach($menuOptions as $key=>$label)<label class="flex items-center gap-2 rounded-xl border border-[#e2e6df] px-3 py-2 text-sm"><input type="checkbox" name="menus[]" value="{{ $key }}" @checked(in_array($key, old('form_context') === 'edit_role_'.$role->id ? old('menus', []) : $selectedMenus))> {{ $label }}</label>@endforeach</div></fieldset>
                        <fieldset><legend class="label">Izin tindakan</legend><div class="grid gap-2 sm:grid-cols-2">@foreach($abilityOptions as $key=>$label)<label class="flex items-center gap-2 rounded-xl border border-[#e2e6df] px-3 py-2 text-sm"><input type="checkbox" name="abilities[]" value="{{ $key }}" @checked(in_array($key, old('form_context') === 'edit_role_'.$role->id ? old('abilities', []) : $selectedAbilities))> {{ $label }}</label>@endforeach</div><p class="mt-2 text-xs text-[#718075]">Izin tindakan berlaku jika menu terkait juga diaktifkan.</p></fieldset>
                        <div class="flex flex-wrap justify-between gap-2"><button class="btn btn-primary">Simpan peran</button></div>
                    </form>
                    @unless($role->is_system)<form method="post" action="{{ route('roles.destroy',$role->id) }}" class="mt-3" onsubmit="return confirm('Hapus peran ini? Pengguna harus dipindahkan ke peran lain dahulu.')">@csrf @method('DELETE')<button class="text-sm font-semibold text-red-700">Hapus peran</button></form>@endunless
                @endif
            </div>
        @endforeach
    </div>

    <dialog x-ref="addRoleDialog" x-init="{{ old('form_context') === 'add_role' ? '$el.showModal()' : '' }}" @click.self="$el.close()" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50">
        <div class="flex items-start justify-between border-b border-[#edf0eb] p-6"><div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">Peran baru</p><h2 class="mt-2 text-xl font-semibold">Tambah peran</h2></div><button type="button" class="text-2xl leading-none text-[#718075]" aria-label="Tutup modal" @click="$refs.addRoleDialog.close()">×</button></div>
        <form method="post" action="{{ route('roles.store') }}" class="space-y-5 p-6">
            @csrf<input type="hidden" name="form_context" value="add_role">
            @if(old('form_context') === 'add_role' && $errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <div><label class="label" for="add-role-name">Nama peran</label><input id="add-role-name" class="field" name="name" value="{{ old('form_context') === 'add_role' ? old('name') : '' }}" maxlength="100" required></div>
            <div><label class="label" for="add-role-key">Kode peran</label><input id="add-role-key" class="field" name="key" value="{{ old('form_context') === 'add_role' ? old('key') : '' }}" maxlength="40" pattern="[a-z][a-z0-9_]*" placeholder="contoh: supervisor" required><p class="mt-1 text-xs text-[#718075]">Huruf kecil, angka, dan garis bawah. Kode tidak dapat diubah setelah disimpan.</p></div>
            <fieldset><legend class="label">Menu yang tampil</legend><div class="grid gap-2 sm:grid-cols-2">@foreach($menuOptions as $key=>$label)<label class="flex items-center gap-2 rounded-xl border border-[#e2e6df] px-3 py-2 text-sm"><input type="checkbox" name="menus[]" value="{{ $key }}" @checked(in_array($key, old('form_context') === 'add_role' ? old('menus', []) : ['dashboard']))> {{ $label }}</label>@endforeach</div></fieldset>
            <fieldset><legend class="label">Izin tindakan</legend><div class="grid gap-2 sm:grid-cols-2">@foreach($abilityOptions as $key=>$label)<label class="flex items-center gap-2 rounded-xl border border-[#e2e6df] px-3 py-2 text-sm"><input type="checkbox" name="abilities[]" value="{{ $key }}" @checked(old('form_context') === 'add_role' && in_array($key, old('abilities', [])))> {{ $label }}</label>@endforeach</div></fieldset>
            <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-light" @click="$refs.addRoleDialog.close()">Batal</button><button class="btn btn-primary">Simpan peran</button></div>
        </form>
    </dialog>
</div>
@endsection
