@extends('layout')
@section('title', 'Detail shift kasir')
@section('content')
<div class="workspace-heading"><div><p class="eyebrow">Operasional / Kas</p><h1>Shift #{{ $shift->id }}</h1><p>{{ $outletName }} · {{ $shift->cashier_name }} · Dibuka {{ \Carbon\Carbon::parse($shift->created_at)->format('d M Y, H:i') }}</p></div><a class="btn btn-light" href="{{ route('shifts') }}">← Semua shift</a></div>
@error('shift')<div class="mb-5 border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>@enderror

<div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div class="surface p-4"><p class="eyebrow">Modal awal</p><p class="mt-2 text-xl font-semibold">Rp{{ number_format($shift->opening_cash, 0, ',', '.') }}</p></div>
    <div class="surface p-4"><p class="eyebrow">Penjualan tunai</p><p class="mt-2 text-xl font-semibold">Rp{{ number_format($salesTotal, 0, ',', '.') }}</p></div>
    <div class="surface p-4"><p class="eyebrow">Refund tunai</p><p class="mt-2 text-xl font-semibold">Rp{{ number_format($refundTotal, 0, ',', '.') }}</p></div>
    <div class="surface p-4"><p class="eyebrow">Kas seharusnya</p><p class="mt-2 text-xl font-semibold">{{ $expected < 0 ? '-' : '' }}Rp{{ number_format(abs($expected), 0, ',', '.') }}</p></div>
</div>

@if($shift->status === 'closed')
<div class="mb-6 grid gap-3 sm:grid-cols-2"><div class="surface p-4"><p class="eyebrow">Uang fisik saat tutup</p><p class="mt-2 text-xl font-semibold">Rp{{ number_format($shift->actual_cash, 0, ',', '.') }}</p></div><div class="surface p-4"><p class="eyebrow">Selisih</p><p class="mt-2 text-xl font-semibold {{ $shift->difference < 0 ? 'text-red-700' : '' }}">{{ $shift->difference > 0 ? '+' : ($shift->difference < 0 ? '-' : '') }}Rp{{ number_format(abs($shift->difference), 0, ',', '.') }}</p></div></div>
@elseif($canOperate)
<div class="mb-6 grid gap-4 lg:grid-cols-2">
    <section class="surface p-5"><h2 class="mb-1 font-semibold">Uang masuk / keluar</h2><p class="mb-4 text-sm text-slate-500">Catat setoran atau pengambilan uang dari laci kasir.</p><form method="post" action="{{ route('shifts.entries', $shift->id) }}" class="grid gap-3">@csrf<div><label class="label" for="entry_type">Jenis</label><select class="field" id="entry_type" name="type"><option value="pay_in">Uang masuk</option><option value="pay_out" @selected(old('type') === 'pay_out')>Uang keluar</option></select></div><div><label class="label" for="entry_amount">Jumlah (Rp)</label><input class="field" id="entry_amount" name="amount" type="number" min="1" required value="{{ old('amount') }}">@error('amount')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div><div><label class="label" for="entry_note">Keterangan</label><input class="field" id="entry_note" name="note" minlength="3" maxlength="500" required value="{{ old('note') }}" placeholder="Contoh: tambah uang kembalian">@error('note')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div><button class="btn btn-light justify-self-start">Catat pergerakan kas</button></form></section>
    <section class="surface p-5"><h2 class="mb-1 font-semibold">Tutup shift</h2><p class="mb-4 text-sm text-slate-500">Hitung seluruh uang fisik di laci. Selisih dihitung otomatis saat ditutup.</p><form method="post" action="{{ route('shifts.close', $shift->id) }}" class="grid gap-3">@csrf<div><label class="label" for="actual_cash">Uang fisik (Rp)</label><input class="field" id="actual_cash" name="actual_cash" type="number" min="0" required value="{{ old('actual_cash') }}">@error('actual_cash')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div><div><label class="label" for="closing_note">Catatan penutupan (opsional)</label><input class="field" id="closing_note" name="closing_note" maxlength="500" value="{{ old('closing_note') }}">@error('closing_note')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div><button class="btn btn-primary justify-self-start" onclick="return confirm('Tutup shift ini setelah uang fisik dihitung?')">Tutup shift</button></form></section>
</div>
@endif

<section class="surface" aria-label="Pergerakan kas"><div class="border-b border-slate-200 px-5 py-4"><h2 class="font-semibold">Pergerakan kas</h2></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>Waktu</th><th>Jenis</th><th>Keterangan</th><th>Petugas</th><th class="amount-column">Perubahan</th></tr></thead><tbody>
@forelse($entries as $entry)
<tr><td class="muted-cell">{{ \Carbon\Carbon::parse($entry->created_at)->format('d M Y, H:i') }}</td><td>{{ ['sale' => 'Penjualan', 'refund' => 'Refund', 'pay_in' => 'Uang masuk', 'pay_out' => 'Uang keluar'][$entry->type] ?? $entry->type }}</td><td>@if($entry->sale_id)<a class="data-link" href="{{ route('sales.show', $entry->sale_id) }}">{{ $entry->note }}</a>@else{{ $entry->note }}@endif</td><td class="muted-cell">{{ $entry->actor_name }}</td><td class="amount-column font-semibold {{ $entry->delta < 0 ? 'text-red-700' : '' }}">{{ $entry->delta > 0 ? '+' : ($entry->delta < 0 ? '-' : '') }}Rp{{ number_format(abs($entry->delta), 0, ',', '.') }}</td></tr>
@empty
<tr><td colspan="5" class="empty-cell">Belum ada pergerakan kas.</td></tr>
@endforelse
</tbody></table></div></section>
@if($shift->opening_note || $shift->closing_note)<p class="mt-4 text-sm text-slate-500">@if($shift->opening_note)Catatan buka: {{ $shift->opening_note }}@endif @if($shift->closing_note) · Catatan tutup: {{ $shift->closing_note }}@endif</p>@endif
@endsection
