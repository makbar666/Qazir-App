@extends('layout')
@section('title', 'Shift kasir')
@section('content')
<div class="workspace-heading"><div><p class="eyebrow">Operasional / Kas</p><h1>Shift kasir</h1><p>{{ $outletName }} · Rekonsiliasi uang tunai per kasir.</p></div>@if($openShift)<a class="btn btn-primary" href="{{ route('shifts.show', $openShift->id) }}">Lihat shift saya</a>@endif</div>

@error('shift')<div class="mb-5 border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>@enderror

@if(!$openShift)
<section class="surface mb-6 p-5" aria-labelledby="open-shift-heading">
    <div class="mb-4"><p class="eyebrow">Kas awal</p><h2 id="open-shift-heading" class="text-lg font-semibold">Buka shift baru</h2><p class="text-sm text-slate-500">Hitung uang tunai di laci sebelum mulai menerima pembayaran.</p></div>
    <form action="{{ route('shifts.store') }}" method="post" class="grid gap-3 md:grid-cols-[180px_1fr_auto] md:items-end">@csrf
        <div><label class="label" for="opening_cash">Modal awal (Rp)</label><input id="opening_cash" name="opening_cash" class="field" type="number" min="0" required value="{{ old('opening_cash', 0) }}">@error('opening_cash')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div>
        <div><label class="label" for="opening_note">Catatan (opsional)</label><input id="opening_note" name="opening_note" class="field" maxlength="500" value="{{ old('opening_note') }}" placeholder="Contoh: serah terima dari kasir sebelumnya">@error('opening_note')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div>
        <button class="btn btn-primary" type="submit">Buka shift</button>
    </form>
</section>
@else
<div class="mb-6 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Shift Anda sedang berjalan. Pembayaran tunai akan tercatat di shift ini.</div>
@endif

<section class="surface" aria-label="Riwayat shift"><div class="border-b border-slate-200 px-5 py-4"><h2 class="font-semibold">Riwayat shift {{ $canManage ? 'outlet' : 'saya' }}</h2></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>Kasir</th><th>Dibuka</th><th>Ditutup</th><th class="amount-column">Kas akhir</th><th class="amount-column">Selisih</th><th>Status</th></tr></thead><tbody>
@forelse($shifts as $shift)
<tr><td><a class="data-link" href="{{ route('shifts.show', $shift->id) }}">{{ $shift->cashier_name }} <span class="text-slate-400">#{{ $shift->id }}</span></a></td><td class="muted-cell">{{ \Carbon\Carbon::parse($shift->created_at)->format('d M Y, H:i') }}</td><td class="muted-cell">{{ $shift->closed_at ? \Carbon\Carbon::parse($shift->closed_at)->format('d M Y, H:i') : '—' }}</td><td class="amount-column">{{ $shift->status === 'closed' ? 'Rp'.number_format($shift->actual_cash, 0, ',', '.') : '—' }}</td><td class="amount-column">{{ $shift->status === 'closed' ? ($shift->difference > 0 ? '+' : '').'Rp'.number_format($shift->difference, 0, ',', '.') : '—' }}</td><td><span class="status status--{{ $shift->status === 'open' ? 'success' : 'muted' }}">{{ $shift->status === 'open' ? 'Berjalan' : 'Ditutup' }}</span></td></tr>
@empty
<tr><td colspan="6" class="empty-cell">Belum ada shift pada outlet ini.</td></tr>
@endforelse
</tbody></table></div><div class="pagination-bar">{{ $shifts->links() }}</div></section>
@endsection
