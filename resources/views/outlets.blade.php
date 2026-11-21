@extends('layout')
@section('title','Outlet')
@section('content')
<div x-data>
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Pengelolaan</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">Outlet</h1>
            <p class="mt-2 text-sm text-[#718075]">Kelola cabang bisnis. Stok dan penjualan dicatat per outlet.</p>
        </div>
        <button type="button" class="btn btn-primary" @click="$refs.addOutletDialog.showModal()">+ Tambah outlet</button>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto"><table class="table">
            <thead><tr><th>Nama outlet</th><th>Kode</th><th>Pengguna aktif</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($outlets as $outlet)
                    <tr><td class="font-semibold">{{ $outlet->name }}</td><td>{{ $outlet->code }}</td><td>{{ $outlet->user_count }}</td><td><button type="button" class="btn btn-light" @click="$refs.editOutlet{{ $outlet->id }}.showModal()">Edit</button></td></tr>
                @endforeach
            </tbody>
        </table></div>
    </div>

    <dialog x-ref="addOutletDialog" x-init="{{ old('form_context') === 'add_outlet' ? '$el.showModal()' : '' }}" @click.self="$el.close()" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50">
        <div class="flex items-start justify-between border-b border-[#edf0eb] p-6"><div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">Cabang baru</p><h2 class="mt-2 text-xl font-semibold">Tambah outlet</h2></div><button type="button" class="text-2xl leading-none text-[#718075]" aria-label="Tutup modal" @click="$refs.addOutletDialog.close()">×</button></div>
        <form method="post" action="{{ route('outlets.store') }}" class="space-y-4 p-6">
            @csrf<input type="hidden" name="form_context" value="add_outlet">
            @if(old('form_context') === 'add_outlet' && $errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <div><label class="label" for="add-outlet-name">Nama outlet</label><input id="add-outlet-name" class="field" name="name" value="{{ old('form_context') === 'add_outlet' ? old('name') : '' }}" maxlength="120" required></div>
            <div><label class="label" for="add-outlet-code">Kode outlet</label><input id="add-outlet-code" class="field" name="code" value="{{ old('form_context') === 'add_outlet' ? old('code') : '' }}" maxlength="40" required><p class="mt-1 text-xs text-[#718075]">Contoh: CABANG-02. Kode harus unik dalam bisnis Anda.</p></div>
            <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-light" @click="$refs.addOutletDialog.close()">Batal</button><button class="btn btn-primary">Simpan outlet</button></div>
        </form>
    </dialog>

    @foreach($outlets as $outlet)
        <dialog x-ref="editOutlet{{ $outlet->id }}" x-init="{{ old('form_context') === 'edit_outlet_'.$outlet->id ? '$el.showModal()' : '' }}" @click.self="$el.close()" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50">
            <div class="flex items-start justify-between border-b border-[#edf0eb] p-6"><div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">Cabang bisnis</p><h2 class="mt-2 text-xl font-semibold">Edit outlet</h2></div><button type="button" class="text-2xl leading-none text-[#718075]" aria-label="Tutup modal" @click="$refs.editOutlet{{ $outlet->id }}.close()">×</button></div>
            <form method="post" action="{{ route('outlets.update',$outlet->id) }}" class="space-y-4 p-6">
                @csrf @method('PUT')<input type="hidden" name="form_context" value="edit_outlet_{{ $outlet->id }}">
                @if(old('form_context') === 'edit_outlet_'.$outlet->id && $errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
                <div><label class="label" for="outlet-name-{{ $outlet->id }}">Nama outlet</label><input id="outlet-name-{{ $outlet->id }}" class="field" name="name" value="{{ old('form_context') === 'edit_outlet_'.$outlet->id ? old('name') : $outlet->name }}" maxlength="120" required></div>
                <div><label class="label" for="outlet-code-{{ $outlet->id }}">Kode outlet</label><input id="outlet-code-{{ $outlet->id }}" class="field" name="code" value="{{ old('form_context') === 'edit_outlet_'.$outlet->id ? old('code') : $outlet->code }}" maxlength="40" required></div>
                <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-light" @click="$refs.editOutlet{{ $outlet->id }}.close()">Batal</button><button class="btn btn-primary">Simpan perubahan</button></div>
            </form>
        </dialog>
    @endforeach
</div>
@endsection
