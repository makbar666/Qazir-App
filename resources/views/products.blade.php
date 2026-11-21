@extends('layout')
@section('title','Produk & stok')
@section('content')
@php
    $canManage = \App\Support\RoleAccess::allowsAbility(auth()->user(), 'products_manage');
    $columns = 3 + ($canManage && !$archived ? 1 : 0) + ($canManage ? 1 : 0);
@endphp
<div x-data="{ editId: null }">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Katalog</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $archived ? 'Produk dihapus' : 'Produk & stok' }}</h1>
            <p class="mt-2 text-sm text-[#718075]">{{ $archived ? 'Produk yang disembunyikan dari katalog dan kasir.' : 'Harga dan jumlah stok yang tersedia di outlet Anda.' }}</p>
        </div>
        @if($canManage)
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-light" href="{{ $archived ? route('products') : route('products',['archived'=>1]) }}">{{ $archived ? '← Daftar produk' : 'Produk dihapus' }}</a>
                @if(!$archived)
                    <button type="button" class="btn btn-primary" @click="$refs.addProductDialog.showModal()">+ Tambah produk</button>
                @endif
            </div>
        @endif
    </div>

    <div class="card overflow-hidden">
        <div class="p-6"><h2 class="font-semibold">{{ $archived ? 'Daftar produk dihapus' : 'Daftar produk' }}</h2></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr>
                    <th>Produk</th><th>Harga</th><th>Stok</th>
                    @if($canManage && !$archived)<th>Penyesuaian stok</th>@endif
                    @if($canManage)<th>Aksi</th>@endif
                </tr></thead>
                <tbody>
                    @forelse($products as $p)
                        <tr>
                            <td class="font-semibold">
                                {{ $p->name }}
                                <div class="text-xs font-normal text-[#8b978d]">{{ $p->sku }} · {{ $archived ? 'Dihapus' : ($p->active ? 'Aktif' : 'Nonaktif') }}</div>
                            </td>
                            <td>Rp{{ number_format($p->price,0,',','.') }}</td>
                            <td><span class="inline-flex rounded-full bg-[#edf5e9] px-3 py-1 text-xs text-[#315e3d]">{{ $p->quantity ?? 0 }} tersedia</span></td>
                            @if($canManage && !$archived)
                                <td>
                                    <form method="post" action="{{ route('products.stock',$p->id) }}" class="flex items-center gap-2" x-data="{ delta: 0 }">
                                        @csrf
                                        <div class="stock-stepper">
                                            <button type="button" aria-label="Kurangi stok satu" @click="delta = Number(delta || 0) - 1">−</button>
                                            <input type="number" name="delta" aria-label="Jumlah penyesuaian stok" inputmode="numeric" step="1" x-model.number="delta" required>
                                            <button type="button" aria-label="Tambah stok satu" @click="delta = Number(delta || 0) + 1">+</button>
                                        </div>
                                        <button class="btn btn-light">Simpan</button>
                                    </form>
                                </td>
                            @endif
                            @if($canManage)
                                <td>
                                    @if($archived)
                                        <form method="post" action="{{ route('products.restore',$p->id) }}">@csrf<button class="btn btn-light">Pulihkan</button></form>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="btn btn-light" @click="editId = editId === {{ $p->id }} ? null : {{ $p->id }}" :aria-expanded="editId === {{ $p->id }}">Edit</button>
                                            <form method="post" action="{{ route('products.destroy',$p->id) }}" onsubmit="return confirm('Hapus produk ini dari katalog? Riwayat penjualan dan stok akan tetap tersimpan.')">
                                                @csrf @method('DELETE')
                                                <button class="btn border border-red-200 bg-red-50 text-red-700">Hapus</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                        @if($canManage && !$archived)
                            <tr x-show="editId === {{ $p->id }}" style="display:none">
                                <td colspan="{{ $columns }}" class="bg-[#fafbf8]">
                                    <form method="post" action="{{ route('products.update',$p->id) }}" class="grid gap-4 p-3 sm:grid-cols-2 xl:grid-cols-3">
                                        @csrf @method('PUT')
                                        <div><label class="label">SKU</label><input class="field" name="sku" value="{{ $p->sku }}" maxlength="40" required></div>
                                        <div><label class="label">Nama produk</label><input class="field" name="name" value="{{ $p->name }}" maxlength="120" required></div>
                                        <div><label class="label">Harga jual (Rp)</label><input class="field" type="number" name="price" min="0" value="{{ $p->price }}" required></div>
                                        <div><label class="label">Menghasilkan poin</label><select class="field" name="point_eligible"><option value="1" @selected($p->point_eligible)>Ya</option><option value="0" @selected(!$p->point_eligible)>Tidak</option></select></div>
                                        <div><label class="label">Status produk</label><select class="field" name="active"><option value="1" @selected($p->active)>Aktif</option><option value="0" @selected(!$p->active)>Nonaktif</option></select></div>
                                        <div class="flex items-end gap-2"><button class="btn btn-primary">Simpan perubahan</button><button type="button" class="btn btn-light" @click="editId = null">Batal</button></div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ $columns }}" class="text-center text-[#89958a]">{{ $archived ? 'Belum ada produk dihapus.' : 'Belum ada produk.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($canManage && !$archived)
        <dialog x-ref="addProductDialog" x-init="{{ old('form_context') === 'add_product' ? '$el.showModal()' : '' }}" @click.self="$el.close()" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50">
            <div class="flex items-start justify-between border-b border-[#edf0eb] p-6">
                <div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">Produk baru</p><h2 class="mt-2 text-xl font-semibold">Tambah produk</h2></div>
                <button type="button" class="text-2xl leading-none text-[#718075]" aria-label="Tutup modal" @click="$refs.addProductDialog.close()">×</button>
            </div>
            <form method="post" action="{{ route('products.store') }}" class="space-y-4 p-6">
                @csrf
                <input type="hidden" name="form_context" value="add_product">
                <div><label class="label" for="new-sku">SKU</label><input class="field" id="new-sku" name="sku" value="{{ old('sku') }}" maxlength="40" required autofocus></div>
                <div><label class="label" for="new-name">Nama produk</label><input class="field" id="new-name" name="name" value="{{ old('name') }}" maxlength="120" required></div>
                <div><label class="label" for="new-price">Harga jual (Rp)</label><input class="field" id="new-price" type="number" name="price" min="0" value="{{ old('price') }}" required></div>
                <div><label class="label" for="new-points">Menghasilkan poin</label><select class="field" id="new-points" name="point_eligible"><option value="1" @selected(old('point_eligible','1')==='1')>Ya</option><option value="0" @selected(old('point_eligible')==='0')>Tidak</option></select></div>
                <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-light" @click="$refs.addProductDialog.close()">Batal</button><button class="btn btn-primary">Simpan produk</button></div>
            </form>
        </dialog>
    @endif
</div>
@endsection
