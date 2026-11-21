@extends('layout')
@section('title', 'Ringkasan')
@section('content')
<div class="workspace-heading">
    <div><p class="eyebrow">Operasional / {{ now()->locale('id')->translatedFormat('l, d F Y') }}</p><h1>Ringkasan outlet</h1><p>Pantau transaksi dan pekerjaan toko hari ini.</p></div>
    @if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'checkout'))<a href="{{ route('checkout') }}" class="btn btn-primary">Transaksi baru <span aria-hidden="true">↗</span></a>@endif
</div>

<section class="metric-strip" aria-label="Metrik hari ini">
    <div class="metric"><span class="metric-label">Penjualan lunas</span><strong>{{ number_format($count, 0, ',', '.') }}</strong><small>Transaksi hari ini</small></div>
    <div class="metric"><span class="metric-label">Pendapatan tercatat</span><strong>Rp{{ number_format($revenue, 0, ',', '.') }}</strong><small>Di luar penjualan yang direfund</small></div>
    <div class="metric"><span class="metric-label">Produk terdaftar</span><strong>{{ number_format($products, 0, ',', '.') }}</strong><small>Dalam katalog bisnis</small></div>
</section>

@if($lowStockCount > 0)
<a href="{{ route('stock_alerts') }}" class="mb-5 flex items-center justify-between gap-3 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><span><strong>{{ $lowStockCount }} produk mencapai batas stok minimum.</strong> Periksa kebutuhan pembelian outlet ini.</span><span aria-hidden="true">→</span></a>
@endif

<div class="dashboard-content">
    <section class="surface" aria-labelledby="recent-title">
        <div class="surface-heading"><div><h2 id="recent-title">Transaksi terbaru</h2><p>Aktivitas penjualan dari outlet aktif</p></div>@if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'sales'))<a href="{{ route('sales') }}" class="text-link">Semua transaksi <span aria-hidden="true">→</span></a>@endif</div>
        <div class="overflow-x-auto"><table class="table"><thead><tr><th>Transaksi</th><th class="compact-hide-mobile">Waktu</th><th class="compact-hide-mobile">Metode</th><th class="amount-column">Total</th><th>Status</th></tr></thead><tbody>
            @forelse($recent as $sale)
                @php($saleStatus = match($sale->status) { 'paid' => 'success', 'pending', 'pending_loyalty' => 'pending', 'refunded', 'cancelled' => 'muted', default => 'pending' })
                <tr><td>@if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'sales') || \App\Support\RoleAccess::allowsMenu(auth()->user(), 'checkout'))<a class="data-link" href="{{ route('sales.show', $sale->id) }}">{{ $sale->number }}</a>@else<strong>{{ $sale->number }}</strong>@endif</td><td class="compact-hide-mobile muted-cell">{{ \Carbon\Carbon::parse($sale->created_at)->format('d M, H:i') }}</td><td class="compact-hide-mobile method-cell">{{ strtoupper($sale->payment_method) }}</td><td class="amount-column font-semibold">Rp{{ number_format($sale->total, 0, ',', '.') }}</td><td><span class="status status--{{ $saleStatus }}">{{ ['paid' => 'Lunas', 'pending' => 'Menunggu QRIS', 'pending_loyalty' => 'Memproses poin', 'refunded' => 'Refund', 'cancelled' => 'Dibatalkan'][$sale->status] ?? $sale->status }}</span></td></tr>
            @empty
                <tr><td colspan="5" class="empty-cell">Belum ada transaksi di outlet ini.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
    <aside class="work-panel" aria-labelledby="work-title"><div class="work-panel-heading"><h2 id="work-title">Akses cepat</h2><p>Langkah operasional</p></div>
        @if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'checkout'))<a href="{{ route('checkout') }}" class="work-link"><span><strong>Buka kasir</strong><small>Catat penjualan baru</small></span><span aria-hidden="true">↗</span></a>@endif
        @if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'products'))<a href="{{ route('products') }}" class="work-link"><span><strong>Produk & stok</strong><small>Kelola katalog outlet</small></span><span aria-hidden="true">↗</span></a>@endif
        @if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'stock_counts'))<a href="{{ route('stock_counts') }}" class="work-link"><span><strong>Stok opname</strong><small>Hitung stok fisik</small></span><span aria-hidden="true">↗</span></a>@endif
        @if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'stock_alerts'))<a href="{{ route('stock_alerts') }}" class="work-link"><span><strong>Stok menipis</strong><small>{{ $lowStockCount }} produk perlu diperiksa</small></span><span aria-hidden="true">↗</span></a>@endif
        @if(\App\Support\RoleAccess::allowsMenu(auth()->user(), 'report'))<a href="{{ route('report') }}" class="work-link"><span><strong>Laporan harian</strong><small>Lihat kinerja penjualan</small></span><span aria-hidden="true">↗</span></a>@endif
    </aside>
</div>
@endsection
