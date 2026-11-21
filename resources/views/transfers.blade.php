@extends('layout')
@section('title', 'Transfer stok')
@section('content')
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Antar-outlet</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Transfer stok</h1>
        <p class="mt-2 text-sm text-[#718075]">Kirim stok dari outlet aktif dan konfirmasi penerimaan di outlet tujuan.</p>
    </div>
    @if($canManage && $outlets->isNotEmpty() && $products->isNotEmpty())
        <button type="button" class="btn btn-primary" onclick="document.getElementById('transfer-dialog').showModal()">+ Kirim stok</button>
    @endif
</div>

<div class="card overflow-hidden">
    <div class="border-b border-[#edf0eb] p-6"><h2 class="font-semibold">Riwayat transfer</h2></div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Nomor / tanggal</th><th>Asal</th><th>Tujuan</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($transfers as $transfer)
                <tr>
                    <td><span class="font-semibold">TRF-{{ str_pad($transfer->id, 6, '0', STR_PAD_LEFT) }}</span><div class="text-xs text-[#8b978d]">{{ \Carbon\Carbon::parse($transfer->created_at)->format('d M Y H:i') }}</div></td>
                    <td>{{ $transfer->source_name }}</td>
                    <td>{{ $transfer->destination_name }}</td>
                    <td><span class="rounded-full px-3 py-1 text-xs {{ $transfer->status === 'received' ? 'bg-[#edf5e9] text-[#315e3d]' : ($transfer->status === 'cancelled' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800') }}">{{ ['in_transit' => 'Dalam pengiriman', 'received' => 'Diterima', 'cancelled' => 'Dibatalkan'][$transfer->status] ?? $transfer->status }}</span></td>
                    <td><a class="btn btn-light" href="{{ route('transfers.show', $transfer->id) }}">Detail</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-[#89958a]">Belum ada transfer stok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-5">{{ $transfers->links() }}</div>

@if($canManage && $outlets->isNotEmpty())
<dialog id="transfer-dialog" x-data="{ selected: {}, search: '' }" x-init="{{ $errors->any() ? '$el.showModal()' : '' }}" @click.self="$el.close()" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50">
    <div class="flex items-start justify-between border-b border-[#edf0eb] p-6">
        <div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">Transfer baru</p><h2 class="mt-2 text-xl font-semibold">Kirim stok</h2></div>
        <button type="button" class="text-2xl leading-none text-[#718075]" aria-label="Tutup modal" @click="$el.close()">×</button>
    </div>
    <form method="post" action="{{ route('transfers.store') }}" class="space-y-5 p-6">
        @csrf
        <p class="text-sm text-[#718075]">Outlet asal: <strong>{{ $sourceOutletName }}</strong></p>
        <div><label class="label" for="destination">Outlet tujuan</label><select class="field" id="destination" name="destination_outlet_id" required><option value="">Pilih outlet</option>@foreach($outlets as $outlet)<option value="{{ $outlet->id }}" @selected(old('destination_outlet_id') == $outlet->id)>{{ $outlet->name }}</option>@endforeach</select></div>
        <div>
            <label class="label" for="product-search">Pilih produk dan jumlah</label>
            <input id="product-search" class="field mb-3" type="search" x-model="search" placeholder="Cari nama atau SKU">
            <div class="max-h-64 space-y-2 overflow-y-auto rounded-xl border border-[#e2e6df] p-2">
                @forelse($products as $product)
                    <div x-show="@js(strtolower($product->name.' '.$product->sku)).includes(search.toLowerCase())" class="flex flex-wrap items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-[#f7f9f5]">
                        <label class="flex min-w-0 items-center gap-3 text-sm"><input type="checkbox" x-model="selected[{{ $product->id }}]" class="h-4 w-4"><span><strong>{{ $product->name }}</strong><small class="block text-[#8b978d]">{{ $product->sku }} · {{ $product->quantity }} tersedia</small></span></label>
                        <div class="flex items-center gap-2"><span class="text-xs text-[#718075]">Jumlah</span><input type="hidden" name="items[{{ $product->id }}][product_id]" value="{{ $product->id }}" :disabled="!selected[{{ $product->id }}]"><input type="number" class="field !w-24 !py-2" name="items[{{ $product->id }}][quantity]" min="1" max="{{ $product->quantity }}" value="1" :disabled="!selected[{{ $product->id }}]" required></div>
                    </div>
                @empty
                    <p class="p-3 text-sm text-[#89958a]">Tidak ada stok yang bisa dikirim dari outlet ini.</p>
                @endforelse
            </div>
        </div>
        <div><label class="label" for="note">Catatan (opsional)</label><textarea class="field" id="note" name="note" rows="2" maxlength="500">{{ old('note') }}</textarea></div>
        <div class="flex justify-end gap-2"><button type="button" class="btn btn-light" @click="$el.close()">Batal</button><button class="btn btn-primary" @click="if (!Object.values(selected).some(Boolean)) { $event.preventDefault(); alert('Pilih minimal satu produk.') }">Kirim stok</button></div>
    </form>
</dialog>
@endif
@endsection
