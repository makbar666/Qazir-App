@extends('layout')
@section('title', 'Stok opname')
@section('content')
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div><p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Inventaris · {{ $outletName }}</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Stok opname</h1><p class="mt-2 text-sm text-[#718075]">Hitung stok fisik per outlet, tinjau selisih, lalu setujui koreksi.</p></div>
    @if($canCount && !$openCount)<button type="button" class="btn btn-primary" onclick="document.getElementById('new-count-dialog').showModal()">+ Mulai opname</button>@endif
</div>
@if($openCount)
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[#d9e7d3] bg-[#eef6e8] p-5"><div><strong>Opname masih terbuka</strong><p class="mt-1 text-sm text-[#53745a]">{{ $openCount->status === 'submitted' ? 'Menunggu persetujuan manager.' : 'Penghitungan belum diajukan.' }}</p></div><a class="btn btn-primary" href="{{ route('stock_counts.show', $openCount->id) }}">Lanjutkan →</a></div>
@endif
<div class="card overflow-hidden"><div class="border-b border-[#edf0eb] p-6"><h2 class="font-semibold">Riwayat opname</h2></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>Nomor / tanggal</th><th>Petugas</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    @forelse($counts as $count)
        <tr><td><strong>OPN-{{ str_pad($count->id, 6, '0', STR_PAD_LEFT) }}</strong><div class="text-xs text-[#8b978d]">{{ \Carbon\Carbon::parse($count->created_at)->format('d M Y H:i') }}</div></td><td>{{ $count->starter_name }}</td><td><span class="rounded-full px-3 py-1 text-xs {{ $count->status === 'approved' ? 'bg-[#edf5e9] text-[#315e3d]' : ($count->status === 'cancelled' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800') }}">{{ ['draft' => 'Sedang dihitung', 'submitted' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'cancelled' => 'Dibatalkan'][$count->status] ?? $count->status }}</span></td><td><a class="btn btn-light" href="{{ route('stock_counts.show', $count->id) }}">Detail</a></td></tr>
    @empty
        <tr><td colspan="4" class="text-center text-[#89958a]">Belum ada stok opname di outlet ini.</td></tr>
    @endforelse
</tbody></table></div></div>
<div class="mt-5">{{ $counts->links() }}</div>
@if($canCount && !$openCount)
<dialog id="new-count-dialog" class="product-modal rounded-2xl border border-[#e2e6df] bg-white p-0 shadow-2xl backdrop:bg-[#193b2b]/50" @click.self="$el.close()"><div class="flex items-start justify-between border-b border-[#edf0eb] p-6"><div><p class="text-xs font-bold uppercase tracking-[.16em] text-[#7e8d80]">{{ $outletName }}</p><h2 class="mt-2 text-xl font-semibold">Mulai stok opname</h2></div><button type="button" class="text-2xl text-[#718075]" aria-label="Tutup modal" onclick="document.getElementById('new-count-dialog').close()">×</button></div><form method="post" action="{{ route('stock_counts.store') }}" class="space-y-5 p-6">@csrf<p class="text-sm leading-6 text-[#718075]">Sistem akan mencatat stok saat ini sebagai pembanding. Isi jumlah fisik semua produk, termasuk yang kosong.</p><div><label class="label" for="count-note">Catatan (opsional)</label><textarea id="count-note" name="note" class="field" rows="2" maxlength="500">{{ old('note') }}</textarea></div><div class="flex justify-end gap-2"><button type="button" class="btn btn-light" onclick="document.getElementById('new-count-dialog').close()">Batal</button><button class="btn btn-primary">Mulai penghitungan</button></div></form></dialog>
@endif
@endsection
