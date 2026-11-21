@extends('layout')
@section('title','Menunggu QRIS')
@section('content')
<div id="payment-live" data-sale-id="{{ $sale->id }}" data-state-url="{{ route('sales.payment.state',$sale->id) }}" data-detail-url="{{ route('sales.show',$sale->id) }}"></div>
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Pembayaran QRIS</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Menunggu pembayaran</h1>
        <p class="mt-2 text-sm text-[#718075]">{{ $sale->number }}</p>
    </div>
    <a class="btn btn-light" href="{{ route('sales') }}">← Daftar penjualan</a>
</div>
<div class="grid gap-6 xl:grid-cols-[1fr_340px]">
    <div class="card p-6 text-center">
        <div class="text-xs font-bold uppercase tracking-[.15em] text-[#698773]">Total yang harus dibayar</div>
        <div class="mt-3 text-4xl font-semibold">Rp{{ number_format($sale->total,0,',','.') }}</div>
        @if($sale->redeem_points)<p class="mt-2 text-sm text-[#718075]">Sudah dipotong {{ $sale->redeem_points }} poin Tanda (Rp{{ number_format($sale->redeem_rupiah,0,',','.') }}).</p>@endif
        @if($sale->midtrans_status==='cancel_requested')
            <p class="mt-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">{{ $sale->midtrans_error }}</p>
            <p class="mt-4 text-sm text-[#718075]">Kode QR disembunyikan selama pembatalan diproses. Stok masih dipesan sampai Midtrans memastikan hasilnya.</p>
        @elseif($sale->midtrans_qr_url)
            <img class="mx-auto mt-6 h-72 w-72 max-w-full object-contain" src="{{ $sale->midtrans_qr_url }}" alt="QRIS dinamis untuk pembayaran {{ $sale->number }}">
            <p class="mt-4 text-sm text-[#718075]">Minta pelanggan memindai QRIS ini. Poin member masuk setelah pembayaran dikonfirmasi.</p>
        @else
            <p class="mt-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Kode QR belum tersedia. {{ $sale->midtrans_error }}</p>
            @if(\App\Support\RoleAccess::allowsMenu(auth()->user(),'midtrans'))
                <a class="mt-4 inline-block text-sm font-semibold text-[#2c6a49]" href="{{ route('midtrans.settings') }}">Buka pengaturan Midtrans →</a>
            @endif
            @if(!$sale->midtrans_transaction_id)
                <form method="post" action="{{ route('sales.payment.retry',$sale->id) }}" class="mt-4">
                    @csrf
                    <button class="btn btn-light">Coba buat QR lagi</button>
                </form>
            @else
                <p class="mt-4 text-sm text-[#718075]">Transaksi sudah dibuat di Midtrans. Periksa statusnya sebelum membuat penjualan baru.</p>
            @endif
        @endif
    </div>
    <div class="space-y-6">
        <div class="rounded-2xl bg-[#dcecc8] p-6 text-[#1c422d]">
            <div class="text-xs font-semibold uppercase tracking-wider">Status pembayaran</div>
            <div class="mt-5 text-2xl font-semibold">{{ $sale->midtrans_status==='cancel_requested' ? 'Pembatalan diminta' : 'Menunggu' }}</div>
            <div class="mt-2 text-sm">{{ $sale->midtrans_status }}</div>
        </div>
        <div class="card p-6">
            <h2 class="font-semibold">Periksa pembayaran</h2>
            <p class="mt-3 text-sm leading-6 text-[#697a6d]">Midtrans mengirim notifikasi otomatis ke server publik. Untuk pengujian lokal, gunakan tombol ini untuk mengambil status terbaru langsung dari Midtrans.</p>
            <form method="post" action="{{ route('sales.payment.check',$sale->id) }}" class="mt-5">
                @csrf
                <button class="btn btn-primary w-full">Periksa status sekarang</button>
            </form>
            <p class="mt-4 text-xs text-[#879388]">Jangan serahkan barang sebelum status menjadi lunas.</p>
        </div>
        <div class="card p-6">
            <h2 class="font-semibold">Batalkan transaksi</h2>
            <p class="mt-3 text-sm leading-6 text-[#697a6d]">Pembayaran yang masih menunggu akan dibatalkan. Stok dikembalikan setelah pembatalan dipastikan.</p>
            <form method="post" action="{{ route('sales.payment.cancel',$sale->id) }}" class="mt-5" onsubmit="return confirm('Batalkan transaksi QRIS ini?')">
                @csrf
                <button class="btn w-full bg-[#a5533b] text-white">{{ $sale->midtrans_status==='cancel_requested' ? 'Coba batalkan lagi' : 'Batalkan transaksi' }}</button>
            </form>
        </div>
    </div>
</div>
@endsection

