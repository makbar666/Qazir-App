@extends('layout')
@section('title', 'Detail transfer')
@section('content')
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div><p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Transfer stok</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">TRF-{{ str_pad($transfer->id, 6, '0', STR_PAD_LEFT) }}</h1><p class="mt-2 text-sm text-[#718075]">{{ $transfer->source_name }} → {{ $transfer->destination_name }}</p></div>
    <a class="btn btn-light" href="{{ route('transfers') }}">← Daftar transfer</a>
</div>
<div class="grid gap-6 lg:grid-cols-[1fr_320px]">
    <div class="card overflow-hidden"><div class="border-b border-[#edf0eb] p-6"><h2 class="font-semibold">Produk yang dikirim</h2></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>Produk</th><th>SKU</th><th>Jumlah</th></tr></thead><tbody>@foreach($items as $item)<tr><td class="font-semibold">{{ $item->name }}</td><td>{{ $item->sku }}</td><td>{{ $item->quantity }}</td></tr>@endforeach</tbody></table></div></div>
    <div class="space-y-5">
        <div class="card p-6"><p class="text-xs font-bold uppercase tracking-[.15em] text-[#5a8a65]">Status</p><p class="mt-3 text-xl font-semibold">{{ ['in_transit' => 'Dalam pengiriman', 'received' => 'Diterima', 'cancelled' => 'Dibatalkan'][$transfer->status] ?? $transfer->status }}</p><p class="mt-3 text-sm text-[#718075]">Dibuat {{ \Carbon\Carbon::parse($transfer->created_at)->format('d M Y H:i') }}</p>@if($transfer->received_at)<p class="mt-1 text-sm text-[#718075]">Diterima {{ \Carbon\Carbon::parse($transfer->received_at)->format('d M Y H:i') }}</p>@endif @if($transfer->cancelled_at)<p class="mt-1 text-sm text-[#718075]">Dibatalkan {{ \Carbon\Carbon::parse($transfer->cancelled_at)->format('d M Y H:i') }}</p>@endif @if($transfer->note)<p class="mt-4 border-t border-[#edf0eb] pt-4 text-sm">{{ $transfer->note }}</p>@endif</div>
        @if($canManage && $transfer->status === 'in_transit' && $activeOutletId === (int) $transfer->destination_outlet_id)
            <div class="card p-6"><h2 class="font-semibold">Terima stok</h2><p class="mt-2 text-sm text-[#718075]">Pastikan seluruh barang sudah tiba dan jumlahnya sesuai sebelum mengonfirmasi.</p><form method="post" action="{{ route('transfers.receive', $transfer->id) }}" class="mt-5" onsubmit="return confirm('Semua barang sudah diterima sesuai jumlah yang dikirim?')">@csrf<button class="btn btn-primary w-full">Konfirmasi penerimaan</button></form></div>
        @endif
        @if($canManage && $transfer->status === 'in_transit' && $activeOutletId === (int) $transfer->source_outlet_id)
            <div class="card p-6"><h2 class="font-semibold">Batalkan transfer</h2><p class="mt-2 text-sm text-[#718075]">Gunakan jika barang belum diserahkan atau seluruh barang sudah kembali ke outlet asal.</p><form method="post" action="{{ route('transfers.cancel', $transfer->id) }}" class="mt-5" onsubmit="return confirm('Barang masih di outlet asal atau seluruhnya sudah kembali? Stok akan dikembalikan ke outlet asal.')">@csrf<button class="btn border border-red-200 bg-red-50 text-red-700 w-full">Batalkan transfer</button></form></div>
        @endif
    </div>
</div>
@endsection
