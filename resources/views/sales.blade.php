@extends('layout')
@section('title', 'Penjualan')
@section('content')
<div class="workspace-heading"><div><p class="eyebrow">Operasional / Transaksi</p><h1>Riwayat penjualan</h1><p>Telusuri pembayaran dan refund pada outlet aktif.</p></div>@if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'checkout'))<a href="{{ route('checkout') }}" class="btn btn-primary">Transaksi baru <span aria-hidden="true">↗</span></a>@endif</div>
<form method="get" class="filter-bar"><div><label class="label" for="sales-date">Tanggal</label><input class="field" id="sales-date" type="date" name="date" value="{{ $date }}"></div><button class="btn btn-light">Filter</button><a href="{{ route('sales') }}" class="btn btn-quiet">Semua</a></form>
<section class="surface" aria-label="Daftar penjualan"><div class="overflow-x-auto"><table class="table"><thead><tr><th>ID transaksi</th><th>Waktu</th><th>Metode</th><th class="amount-column">Nilai</th><th>Status</th></tr></thead><tbody>
@forelse($sales as $sale)
    @php($saleStatus = match($sale->status) { 'paid' => 'success', 'pending', 'pending_loyalty' => 'pending', 'refunded', 'cancelled' => 'muted', default => 'pending' })
    <tr><td><a class="data-link" href="{{ route('sales.show', $sale->id) }}">{{ $sale->number }}</a></td><td class="muted-cell">{{ \Carbon\Carbon::parse($sale->created_at)->format('d M Y, H:i') }}</td><td class="method-cell">{{ strtoupper($sale->payment_method) }}</td><td class="amount-column font-semibold">Rp{{ number_format($sale->total, 0, ',', '.') }}</td><td><span class="status status--{{ $sale->status === 'paid' && $sale->refund_status ? 'pending' : $saleStatus }}">{{ $sale->status === 'paid' && $sale->refund_status ? (in_array($sale->refund_status, ['review', 'request_failed']) ? 'Refund perlu diperiksa' : 'Refund diproses') : (['paid' => 'Lunas', 'pending' => 'Menunggu QRIS', 'pending_loyalty' => 'Memproses poin', 'refunded' => 'Refund', 'cancelled' => 'Dibatalkan'][$sale->status] ?? $sale->status) }}</span></td></tr>
@empty
    <tr><td colspan="5" class="empty-cell">Tidak ada penjualan untuk filter ini.</td></tr>
@endforelse
</tbody></table></div><div class="pagination-bar">{{ $sales->links() }}</div></section>
@endsection
