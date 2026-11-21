@extends('layout')
@section('title', 'Rekonsiliasi harian')
@section('content')
@php($net = $summary['gross'] - $summary['refundTotal'])
<div class="workspace-heading flex-wrap">
    <div><p class="eyebrow">Keuangan / Outlet</p><h1>Rekonsiliasi harian</h1><p>{{ $outletName }} · Hari operasional {{ $summary['timezone'] }}</p></div>
    @if($review)
        <span class="status status--{{ $review->snapshot_hash === $summary['hash'] ? 'success' : 'pending' }}">{{ $review->snapshot_hash === $summary['hash'] ? 'Disahkan' : 'Perlu tinjau ulang' }}</span>
    @else
        <span class="status status--muted">Belum disahkan</span>
    @endif
</div>

<form method="get" action="{{ route('reconciliation') }}" class="filter-bar">
    <div><label class="label" for="reconciliation-date">Tanggal</label><input id="reconciliation-date" class="field" type="date" name="date" max="{{ app(\App\Services\DailyReconciliationService::class)->today() }}" value="{{ $summary['date'] }}"></div>
    <button class="btn btn-light">Tampilkan</button>
</form>

<section class="metric-strip" aria-label="Total penjualan dan refund">
    <div class="metric"><span class="metric-label">Pembayaran masuk</span><strong>Rp{{ number_format($summary['gross'], 0, ',', '.') }}</strong><small>{{ $summary['salesCount'] }} transaksi lunas pada tanggal ini</small></div>
    <div class="metric"><span class="metric-label">Refund selesai</span><strong>Rp{{ number_format($summary['refundTotal'], 0, ',', '.') }}</strong><small>{{ $summary['refunds']->count() }} refund pada tanggal ini</small></div>
    <div class="metric"><span class="metric-label">Bersih operasional</span><strong>{{ $net < 0 ? '-' : '' }}Rp{{ number_format(abs($net), 0, ',', '.') }}</strong><small>Pembayaran masuk dikurangi refund selesai</small></div>
</section>

@if($summary['blocking'] || $summary['warnings'])
<section class="surface mb-5 p-5" aria-label="Hal yang perlu ditinjau">
    <h2 class="mb-3 font-semibold">Hal yang perlu ditinjau</h2>
    @foreach($summary['blocking'] as $issue)<p class="mb-2 text-sm text-red-700">● {{ $issue }}</p>@endforeach
    @foreach($summary['warnings'] as $warning)<p class="mb-2 text-sm text-amber-800">● {{ $warning }}</p>@endforeach
    <p class="mt-3 text-xs text-slate-500">Selisih kas dan QRIS manual dapat disahkan dengan catatan. Transaksi atau status QRIS yang belum selesai harus diselesaikan dahulu.</p>
</section>
@endif

@if($summary['pending']->isNotEmpty())
<section class="surface mb-5" aria-labelledby="pending-reconciliation-title">
    <div class="surface-heading"><div><h2 id="pending-reconciliation-title">Transaksi belum selesai</h2><p>Termasuk transaksi dari hari sebelumnya yang masih menunggu.</p></div></div>
    <div class="overflow-x-auto"><table class="table"><thead><tr><th>Penjualan</th><th>Metode</th><th>Status</th><th class="amount-column">Tagihan</th></tr></thead><tbody>
        @foreach($summary['pending'] as $sale)
            <tr><td><a class="data-link" href="{{ $sale->status === 'pending' ? route('sales.payment', $sale->id) : route('sales') }}">{{ $sale->number }}</a></td><td>{{ strtoupper($sale->payment_method) }}</td><td>{{ $sale->status }}</td><td class="amount-column">Rp{{ number_format($sale->total, 0, ',', '.') }}</td></tr>
        @endforeach
    </tbody></table></div>
</section>
@endif

<div class="grid gap-5 xl:grid-cols-2">
    <section class="surface" aria-labelledby="cash-reconciliation-title">
        <div class="surface-heading"><div><h2 id="cash-reconciliation-title">Tunai & shift</h2><p>Nominal transaksi pada tanggal terpilih; saldo laci dihitung per shift.</p></div></div>
        <div class="border-t border-slate-100 px-5 py-4 text-sm">
            <div class="flex justify-between py-1"><span>Penjualan tunai</span><strong>Rp{{ number_format($summary['cashSales'], 0, ',', '.') }}</strong></div>
            <div class="flex justify-between py-1"><span>Refund tunai</span><strong>− Rp{{ number_format($summary['cashRefunds'], 0, ',', '.') }}</strong></div>
            <div class="flex justify-between py-1"><span>Catatan penjualan/refund pada shift</span><strong>{{ $summary['cashLedger'] < 0 ? '-' : '' }}Rp{{ number_format(abs($summary['cashLedger']), 0, ',', '.') }}</strong></div>
            <div class="mt-2 flex justify-between border-t border-slate-100 pt-3 font-semibold"><span>Selisih catatan transaksi</span><strong>{{ $summary['cashLedgerDifference'] < 0 ? '-' : '' }}Rp{{ number_format(abs($summary['cashLedgerDifference']), 0, ',', '.') }}</strong></div>
        </div>
        <div class="overflow-x-auto border-t border-slate-100"><table class="table"><thead><tr><th>Shift / Kasir</th><th>Status</th><th class="amount-column">Kas fisik</th><th class="amount-column">Selisih</th></tr></thead><tbody>
            @forelse($summary['shifts'] as $shift)
                <tr><td><a class="data-link" href="{{ route('shifts.show', $shift->id) }}">#{{ $shift->id }} · {{ $shift->cashier_name }}</a></td><td>{{ $shift->status === 'closed' ? 'Ditutup' : 'Berjalan' }}</td><td class="amount-column">{{ $shift->status === 'closed' ? 'Rp'.number_format($shift->actual_cash, 0, ',', '.') : '—' }}</td><td class="amount-column">{{ $shift->status === 'closed' ? ($shift->difference < 0 ? '-' : '').'Rp'.number_format(abs($shift->difference), 0, ',', '.') : '—' }}</td></tr>
            @empty<tr><td colspan="4" class="empty-cell">Belum ada shift yang ditutup pada tanggal ini.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="surface" aria-labelledby="qris-reconciliation-title">
        <div class="surface-heading"><div><h2 id="qris-reconciliation-title">QRIS</h2><p>Penjualan, refund, dan permintaan refund terbuka dibandingkan dengan status Midtrans.</p></div></div>
        <div class="border-t border-slate-100 px-5 py-4 text-sm">
            <div class="flex justify-between py-1"><span>Pembayaran QRIS</span><strong>Rp{{ number_format($summary['qrisGross'], 0, ',', '.') }}</strong></div>
            <div class="flex justify-between py-1"><span>Refund QRIS selesai</span><strong>− Rp{{ number_format($summary['qrisRefunds'], 0, ',', '.') }}</strong></div>
            <div class="flex justify-between py-1"><span>QRIS manual</span><strong>{{ $summary['manualQrisCount'] }} pembayaran · {{ $summary['manualQrisRefundCount'] }} refund</strong></div>
            <form method="post" action="{{ route('reconciliation.check') }}" class="mt-3">@csrf<input type="hidden" name="date" value="{{ $summary['date'] }}"><input type="hidden" name="outlet_id" value="{{ $outletId }}"><button class="btn btn-light" @if($summary['qrisRows']->isEmpty()) disabled @endif>Periksa hingga 10 QRIS ke Midtrans</button></form>
        </div>
        <div class="overflow-x-auto border-t border-slate-100"><table class="table"><thead><tr><th>Penjualan</th><th>Status POS</th><th>Status Midtrans</th><th>Hasil</th></tr></thead><tbody>
            @forelse($summary['qrisRows'] as $row)
                <tr><td><a class="data-link" href="{{ route('sales.show', $row->sale->id) }}">{{ $row->sale->number }}</a><div class="muted-cell">Rp{{ number_format($row->sale->total, 0, ',', '.') }}</div></td><td>{{ $row->sale->status }}</td><td>{{ $row->check?->remote_status ?? '—' }}</td><td><span class="status status--{{ $row->problem ? 'pending' : 'success' }}">{{ $row->problem ?? 'Cocok' }}</span></td></tr>
            @empty<tr><td colspan="4" class="empty-cell">Tidak ada QRIS Midtrans untuk tanggal ini.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>

<section class="surface mt-5 p-5" aria-labelledby="reconciliation-review-title">
    <h2 id="reconciliation-review-title" class="font-semibold">Pengesahan harian</h2>
    @if($review)
        <p class="mt-2 text-sm text-slate-600">Terakhir disahkan {{ $review->reviewer_name }} pada {{ \Carbon\Carbon::parse($review->created_at)->timezone($summary['timezone'])->format('d M Y H:i') }}.@if($review->note) Catatan: {{ $review->note }}@endif</p>
        @if($review->snapshot_hash !== $summary['hash'])<p class="mt-2 text-sm text-amber-800">Data berubah setelah pengesahan. Periksa kembali lalu sahkan versi terbaru.</p>@endif
    @endif
    <form method="post" action="{{ route('reconciliation.review') }}" class="mt-4 flex flex-col gap-3 md:flex-row md:items-end">@csrf
        <input type="hidden" name="date" value="{{ $summary['date'] }}">
        <input type="hidden" name="outlet_id" value="{{ $outletId }}">
        <div class="min-w-0 flex-1"><label class="label" for="reconciliation-note">Catatan pemeriksaan {{ $summary['warnings'] ? '(wajib jika ada selisih)' : '(opsional)' }}</label><textarea id="reconciliation-note" class="field" name="note" rows="2" maxlength="1000" placeholder="Jelaskan selisih kas atau verifikasi QRIS manual">{{ old('note') }}</textarea></div>
        <button class="btn btn-primary" @if($summary['blocking'] || ($review && $review->snapshot_hash === $summary['hash'])) disabled @endif>Sahkan rekonsiliasi</button>
    </form>
    <p class="mt-3 text-xs text-slate-500">Payout Midtrans dihitung terpisah dari penjualan harian karena jadwal transfer dan biaya dapat berbeda. Cocokkan jumlah bersih payout dengan laporan Midtrans saat dana masuk rekening.</p>
</section>
@endsection
