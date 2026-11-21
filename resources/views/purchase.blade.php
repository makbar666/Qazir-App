@extends('layout')
@section('title', 'Detail pembelian')
@section('content')
@php($open = in_array($order->status, ['ordered', 'partial'], true))
<div class="workspace-heading"><div><p class="eyebrow">Inventaris / Pembelian</p><h1>{{ $order->number }}</h1><p>{{ $outletName }} · {{ $order->supplier_name }} · {{ \Carbon\Carbon::parse($order->created_at)->format('d M Y, H:i') }}</p></div><a class="btn btn-light" href="{{ route('purchases') }}">← Daftar pembelian</a></div>
@if($order->status === 'cancelled' && $items->sum('received_quantity') > 0)<div class="mb-5 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Sisa pesanan dibatalkan. Barang yang sudah diterima tetap masuk stok.</div>@endif
<div class="mb-5 grid gap-3 sm:grid-cols-3"><div class="surface p-4"><p class="eyebrow">Status</p><p class="mt-2 font-semibold">{{ ['ordered' => 'Dipesan', 'partial' => 'Diterima sebagian', 'received' => 'Selesai', 'cancelled' => 'Dibatalkan'][$order->status] ?? $order->status }}</p></div><div class="surface p-4"><p class="eyebrow">Nilai pesanan</p><p class="mt-2 font-semibold">Rp{{ number_format($items->sum(fn($item) => $item->quantity * $item->unit_cost), 0, ',', '.') }}</p></div><div class="surface p-4"><p class="eyebrow">Nilai diterima</p><p class="mt-2 font-semibold">Rp{{ number_format($items->sum(fn($item) => $item->received_quantity * $item->unit_cost), 0, ',', '.') }}</p></div></div>

@if($canManage && $open)
<form method="post" action="{{ route('purchases.receive', $order->id) }}">@csrf<input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
@endif
<section class="surface"><div class="border-b border-slate-200 px-5 py-4"><h2 class="font-semibold">Barang dalam pesanan</h2><p class="text-sm text-slate-500">Isi jumlah yang benar-benar tiba untuk mencatat penerimaan.</p></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>Produk</th><th class="amount-column">Dipesan</th><th class="amount-column">Diterima</th><th class="amount-column">Sisa</th><th class="amount-column">Harga beli</th>@if($canManage && $open)<th class="amount-column">Terima sekarang</th>@endif</tr></thead><tbody>
@foreach($items as $item)
@php($remaining = $item->quantity - $item->received_quantity)
<tr><td class="font-semibold">{{ $item->name }}<small class="block font-normal text-slate-500">{{ $item->sku }}</small></td><td class="amount-column">{{ number_format($item->quantity, 0, ',', '.') }}</td><td class="amount-column">{{ number_format($item->received_quantity, 0, ',', '.') }}</td><td class="amount-column">{{ number_format($remaining, 0, ',', '.') }}</td><td class="amount-column">Rp{{ number_format($item->unit_cost, 0, ',', '.') }}</td>@if($canManage && $open)<td class="amount-column"><input class="field !ml-auto !w-28" type="number" name="quantities[{{ $item->id }}]" min="0" max="{{ $remaining }}" value="{{ old('quantities.'.$item->id, 0) }}" aria-label="Jumlah {{ $item->name }} diterima" required></td>@endif</tr>
@endforeach
</tbody></table></div></section>
@if($canManage && $open)
<section class="surface mt-4 p-5"><h2 class="mb-3 font-semibold">Catat penerimaan</h2><div class="grid gap-3 md:grid-cols-[1fr_1fr_auto] md:items-end"><div><label class="label" for="receipt_reference">Nomor surat jalan / faktur (opsional)</label><input class="field" id="receipt_reference" name="reference" maxlength="255" value="{{ old('reference') }}"></div><div><label class="label" for="receipt_note">Catatan (opsional)</label><input class="field" id="receipt_note" name="note" maxlength="1000" value="{{ old('note') }}"></div><button class="btn btn-primary">Terima barang</button></div><p class="mt-3 text-xs text-slate-500">Jumlah nol tidak dicatat. Penerimaan berikutnya dapat dilakukan untuk sisa pesanan.</p></section>
</form>
<form method="post" action="{{ route('purchases.cancel', $order->id) }}" class="mt-4" onsubmit="return confirm('Batalkan sisa pesanan? Barang yang sudah diterima tetap tercatat.')">@csrf<button class="btn btn-light text-red-700">Batalkan sisa pesanan</button></form>
@endif

<section class="surface mt-5"><div class="border-b border-slate-200 px-5 py-4"><h2 class="font-semibold">Riwayat penerimaan</h2></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>Tanggal</th><th>Diterima oleh</th><th>Referensi</th><th>Barang</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td class="muted-cell">{{ \Carbon\Carbon::parse($receipt->created_at)->format('d M Y, H:i') }}</td><td>{{ $receipt->receiver_name }}</td><td>{{ $receipt->reference ?: '—' }}@if($receipt->note)<small class="block text-slate-500">{{ $receipt->note }}</small>@endif</td><td>@foreach($receiptItems->where('purchase_receipt_id', $receipt->id) as $received)<div>{{ $received->name }} × {{ number_format($received->quantity, 0, ',', '.') }}</div>@endforeach</td></tr>
@empty
<tr><td colspan="4" class="empty-cell">Belum ada barang diterima.</td></tr>
@endforelse
</tbody></table></div></section>
@if($order->supplier_reference || $order->note)<p class="mt-4 text-sm text-slate-500">@if($order->supplier_reference)Referensi pemasok: {{ $order->supplier_reference }}@endif @if($order->note) · Catatan: {{ $order->note }}@endif</p>@endif
@endsection
