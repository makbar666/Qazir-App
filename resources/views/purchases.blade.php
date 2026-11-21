@extends('layout')
@section('title', 'Pembelian')
@section('content')
<div class="workspace-heading"><div><p class="eyebrow">Inventaris / {{ $outletName }}</p><h1>Pembelian</h1><p>Pesanan pemasok dan penerimaan stok untuk outlet aktif.</p></div><div class="flex flex-wrap gap-2"><a class="btn btn-light" href="{{ route('purchases.suppliers') }}">Pemasok</a>@if($canManage)<a class="btn btn-primary" href="{{ route('purchases.create') }}">+ Buat pesanan</a>@endif</div></div>
<section class="surface" aria-label="Daftar pembelian"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Pesanan</th><th>Pemasok</th><th>Dibuat oleh</th><th>Tanggal</th><th>Status</th></tr></thead><tbody>
@forelse($orders as $order)
@php($tone = match($order->status) { 'received' => 'success', 'cancelled' => 'muted', default => 'pending' })
<tr><td><a class="data-link" href="{{ route('purchases.show', $order->id) }}">{{ $order->number }}</a></td><td>{{ $order->supplier_name }}</td><td class="muted-cell">{{ $order->creator_name }}</td><td class="muted-cell">{{ \Carbon\Carbon::parse($order->created_at)->format('d M Y, H:i') }}</td><td><span class="status status--{{ $tone }}">{{ ['ordered' => 'Dipesan', 'partial' => 'Diterima sebagian', 'received' => 'Selesai', 'cancelled' => 'Dibatalkan'][$order->status] ?? $order->status }}</span></td></tr>
@empty
<tr><td colspan="5" class="empty-cell">Belum ada pesanan pembelian di outlet ini.</td></tr>
@endforelse
</tbody></table></div><div class="pagination-bar">{{ $orders->links() }}</div></section>
@endsection
