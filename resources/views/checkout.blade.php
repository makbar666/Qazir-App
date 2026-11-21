@extends('layout')
@section('title','Kasir')
@section('content')
<div x-data="posCheckout(@js($products), @js($midtransReady), @js(auth()->user()->role), @js($cashShiftOpen), @js($cashShiftEnforced))">
    <div class="mb-8">
        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#5a8a65]">Kasir</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Transaksi baru</h1>
        <p class="mt-2 text-sm text-[#718075]">Pilih produk, tentukan jumlah, lalu catat pembayaran yang sudah diterima.</p>
    </div>

    @if($cashShiftEnforced && !$cashShiftOpen)
        <div class="mb-5 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Pembayaran tunai membutuhkan shift kasir yang terbuka. <a class="font-semibold underline" href="{{ route('shifts') }}">Buka shift</a></div>
    @endif
    <form method="post" action="{{ route('checkout.store') }}" class="grid gap-6 xl:grid-cols-[1fr_360px]" @submit="submitSale($event)">
        @csrf
        <input type="hidden" name="request_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
        <div class="space-y-6">
            <div class="card overflow-hidden">
                <div class="p-6">
                    <div class="text-xs font-bold uppercase tracking-[.15em] text-[#698773]">01 / Keranjang</div>
                    <h2 class="mt-2 text-lg font-semibold">Pilih produk</h2>
                    <div class="mt-5 flex items-center gap-2">
                        <input class="field" type="search" x-model.debounce.150ms="productQuery" @input="setProductPage(1)" aria-label="Cari produk" placeholder="Cari nama atau SKU produk">
                        <button type="button" class="btn btn-light" x-show="productQuery" @click="productQuery = ''; productPage = 1">Hapus</button>
                    </div>
                    <p class="mt-2 text-xs text-[#718075]" x-show="productQuery" x-text="filteredProducts.length + ' produk ditemukan'"></p>
                </div>
                <div class="overflow-x-auto"><table class="table"><thead><tr><th>Produk</th><th>Harga</th><th>Stok</th><th>Jumlah</th></tr></thead><tbody x-ref="productRows">
                    @forelse($products as $p)
                    <tr x-show="pageProducts.some(p => Number(p.id) === {{ $p->id }})"><td class="font-semibold">{{ $p->name }}<div class="text-xs font-normal text-[#8b978d]">{{ $p->sku }}</div></td><td>Rp{{ number_format($p->price,0,',','.') }}</td><td>{{ $p->quantity }}</td><td>
                        <input type="hidden" name="items[{{ $p->id }}][product_id]" value="{{ $p->id }}" :disabled="!qty[{{ $p->id }}]">
                        <div class="stock-stepper">
                            <button type="button" aria-label="Kurangi jumlah {{ $p->name }}" :disabled="!Number(qty[{{ $p->id }}] || 0)" @click="qty[{{ $p->id }}] = Math.max(0, Number(qty[{{ $p->id }}] || 0) - 1)">−</button>
                            <input type="number" aria-label="Jumlah {{ $p->name }}" :name="qty[{{ $p->id }}] > 0 ? 'items[{{ $p->id }}][quantity]' : null" min="0" max="{{ $p->quantity }}" step="1" inputmode="numeric" x-model.number="qty[{{ $p->id }}]">
                            <button type="button" aria-label="Tambah jumlah {{ $p->name }}" :disabled="Number(qty[{{ $p->id }}] || 0) >= {{ $p->quantity }}" @click="qty[{{ $p->id }}] = Math.min({{ $p->quantity }}, Number(qty[{{ $p->id }}] || 0) + 1)">+</button>
                        </div>
                    </td></tr>
                    @empty<tr><td colspan="4" class="text-center text-[#89958a]">Belum ada produk dengan stok. Tambah produk dan stok dahulu.</td></tr>@endforelse
                    @if($products->isNotEmpty())
                    <tr x-show="productQuery.trim() && !filteredProducts.length"><td colspan="4" class="text-center text-[#89958a]">Produk tidak ditemukan. Coba nama atau SKU lain.</td></tr>
                    @endif
                </tbody></table></div>
                @if($products->isNotEmpty())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#e7ebe5] px-6 py-4 text-sm">
                    <span class="text-[#718075]" x-text="filteredProducts.length ? `Menampilkan ${(productPage - 1) * 5 + 1}–${Math.min(productPage * 5, filteredProducts.length)} dari ${filteredProducts.length} produk` : '0 produk'"></span>
                    <nav class="product-pages flex flex-wrap items-center gap-1" aria-label="Halaman produk" x-show="totalProductPages > 1">
                        <button type="button" class="btn btn-light" :disabled="productPage === 1" @click="setProductPage(productPage - 1)">Sebelumnya</button>
                        <template x-for="page in productPageNumbers" :key="page">
                            <button type="button" class="btn btn-light min-w-10" :aria-current="page === productPage ? 'page' : null" @click="setProductPage(page)" x-text="page"></button>
                        </template>
                        <button type="button" class="btn btn-light" :disabled="productPage === totalProductPages" @click="setProductPage(productPage + 1)">Berikutnya</button>
                    </nav>
                </div>
                @endif
            </div>

            @if($integrationReady)
            <div class="card p-6">
                <div class="text-xs font-bold uppercase tracking-[.15em] text-[#698773]">02 / Member Tanda</div>
                <h2 class="mt-2 text-lg font-semibold">Hubungkan member</h2>
                <p class="mt-2 text-sm text-[#718075]">Cari nama, kode, atau nomor telepon member. Biarkan kosong untuk penjualan tanpa poin.</p>
                <div class="mt-5 flex gap-2">
                    <input class="field" type="search" x-model="memberQuery" @input="clearMember()" @keydown.enter.prevent="lookup()" placeholder="Nama, kode, atau nomor telepon">
                    <button type="button" class="btn btn-light min-w-24" @click="lookup()" :disabled="searching"><span x-show="!searching">Cari</span><span x-show="searching" class="flex items-center gap-2"><span class="h-4 w-4 animate-spin rounded-full border-2 border-[#245c43] border-t-transparent"></span>Mencari</span></button>
                </div>
                <input type="hidden" name="member_external_id" :value="member?.id || ''">
                <p class="mt-3 text-sm text-[#a5533b]" x-show="memberMessage" x-text="memberMessage"></p>
                <p class="mt-2 text-xs text-[#718075]" x-show="searching">Mengambil daftar member dari Tanda...</p>
                <div class="motion-panel mt-4 space-y-2" x-show="results.length && !member">
                    <div class="text-xs font-bold uppercase tracking-[.12em] text-[#7e8d80]">Hasil pencarian</div>
                    <template x-for="result in results" :key="result.id">
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#e2e6df] bg-[#fafbf8] p-4">
                            <div><div class="font-semibold" x-text="result.name"></div><div class="mt-1 text-xs text-[#718075]" x-text="result.code+' · '+result.phone+' · '+result.point_balance+' poin'"></div></div>
                            <button type="button" class="btn btn-light" @click="selectMember(result)">Pilih</button>
                        </div>
                    </template>
                </div>
                <div class="motion-panel mt-4 rounded-xl border border-[#c7e2c8] bg-[#edf8ed] p-4" x-show="member">
                    <div class="text-xs font-bold uppercase tracking-[.12em] text-[#5a8a65]">Member dipilih</div>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-3"><div><strong x-text="member?.name"></strong><div class="mt-1 text-xs text-[#53745a]" x-text="member ? member.code+' · '+member.point_balance+' poin' : ''"></div></div><button type="button" class="text-sm font-semibold text-[#2c6a49]" @click="clearMember()">Ganti member</button></div>
                </div>
                <div class="mt-5" x-show="member && redemption">
                    <label class="label" for="redeem-points">Tukar poin</label>
                    <input id="redeem-points" class="field" type="number" name="redeem_points" min="0" step="1" inputmode="numeric" x-model.number="redeemPoints">
                    <p class="mt-2 text-xs text-[#718075]" x-text="redemption ? `Saldo ${member?.point_balance || 0} poin · Minimal ${redemption.minimum_redeem} poin · 1 poin = ${rupiah(redemption.redeem_value)}` : ''"></p>
                    <p class="mt-1 text-xs text-[#718075]">Nilai penukaran akan diperiksa ulang oleh Tanda sebelum transaksi dicatat.</p>
                </div>
                <p class="mt-3 text-xs text-[#a5533b]" x-show="member && !redemption">Aturan penukaran poin Tanda belum aktif.</p>
            </div>
            @endif

            <div class="card p-6">
                <div class="text-xs font-bold uppercase tracking-[.15em] text-[#698773]">{{ $integrationReady ? '03' : '02' }} / Pembayaran</div>
                <h2 class="mt-2 text-lg font-semibold">Rincian pembayaran</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="inline-flex min-h-[35px] cursor-pointer items-center gap-2 text-sm font-medium text-[#35473a]">
                            <input type="checkbox" class="h-4 w-4 accent-[#245c43]" x-model="discountEnabled" @change="if (!$event.target.checked) discount = 0">
                            Gunakan diskon transaksi
                        </label>
                        <input type="hidden" name="discount" :value="appliedDiscount">
                        <div class="mt-2" x-show="discountEnabled" x-transition.opacity.duration.150ms style="display:none">
                            <label class="label" for="transaction-discount">Nominal diskon (Rp)</label>
                            <input id="transaction-discount" class="field" type="number" min="0" step="1" x-model.number="discount" :required="discountEnabled">
                        </div>
                    </div>
                    <fieldset>
                        <legend class="label">Metode pembayaran</legend>
                        <div class="payment-methods">
                            <label class="payment-method-choice"><input type="radio" name="payment_method" value="cash" x-model="method" checked><span>Tunai</span></label>
                            <label class="payment-method-choice"><input type="radio" name="payment_method" value="qris" x-model="method"><span>{{ $midtransReady ? 'QRIS Midtrans' : 'QRIS manual' }}</span></label>
                        </div>
                    </fieldset>
                    <div class="sm:col-span-2" x-show="!dynamicQris"><label class="label" for="amount-received">Jumlah diterima (Rp)</label><input id="amount-received" class="field payment-received-field" type="number" name="amount_received" min="0" inputmode="numeric" x-model.number="received" :required="!dynamicQris"></div>
                    <p x-show="dynamicQris" class="self-end text-sm text-[#53745a] sm:col-span-2">Kode QR dibuat setelah transaksi disimpan. Poin masuk setelah Midtrans mengonfirmasi pembayaran.</p>
                </div>
            </div>
        </div>

        <div><div class="card sticky top-5 overflow-hidden">
            <div class="bg-[#193b2b] p-6 text-white"><div class="text-xs font-bold uppercase tracking-[.17em] text-[#a9c8ad]">Ringkasan pembayaran</div><div class="mt-5 text-4xl font-semibold" x-text="rupiah(total)"></div><div class="mt-2 text-xs text-[#afc6b2]">Jumlah yang perlu dibayar</div></div>
            <div class="space-y-4 p-6 text-sm">
                <div class="flex justify-between"><span class="text-[#728076]">Item dipilih</span><strong x-text="selectedCount"></strong></div>
                <div class="flex justify-between"><span class="text-[#728076]">Subtotal</span><span x-text="rupiah(subtotal)"></span></div>
                <div class="flex justify-between" x-show="discountEnabled"><span class="text-[#728076]">Diskon</span><span x-text="'- '+rupiah(appliedDiscount)"></span></div>
                <div class="flex justify-between" x-show="redeemPoints > 0"><span class="text-[#728076]">Tukar poin</span><span x-text="'- '+rupiah(redeemValue)"></span></div>
                <div class="border-t pt-4 flex justify-between"><span>Kembalian</span><strong class="text-[#2b7a4b]" x-text="rupiah(change)"></strong></div>
                <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800" x-show="!submitting && paymentProblem && !submitError" x-text="paymentProblem"></p>
                <p class="rounded-lg bg-red-50 p-3 text-xs text-red-700" x-show="submitError" x-text="submitError"></p>
                <button class="btn btn-primary w-full" :disabled="submitting"><span x-show="!submitting">Catat pembayaran →</span><span x-show="submitting" class="flex items-center justify-center gap-2"><span class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>Mencatat pembayaran...</span></button>
                <p class="text-center text-xs leading-5 text-[#8b978d]">Pastikan pembayaran sudah diterima sebelum mencatat transaksi.</p>
            </div>
        </div></div>
    </form>
</div>
<script>
function posCheckout(products,midtransReady,operatorRole,cashShiftOpen,cashShiftEnforced){return {
    products,midtransReady,operatorRole,cashShiftOpen,cashShiftEnforced,method:'cash',qty:{},productQuery:'',productPage:1,discountEnabled:false,discount:0,received:0,memberQuery:'',member:null,redemption:null,redeemPoints:0,results:[],memberMessage:'',searching:false,searchRequest:0,submitting:false,submitError:'',
    matchesProduct(name,sku){const query=this.productQuery.trim().toLocaleLowerCase('id-ID');return !query || String(name).toLocaleLowerCase('id-ID').includes(query) || String(sku).toLocaleLowerCase('id-ID').includes(query)},
    get filteredProducts(){return this.products.filter(p=>this.matchesProduct(p.name,p.sku))},
    get totalProductPages(){return Math.max(1,Math.ceil(this.filteredProducts.length/5))},
    get pageProducts(){return this.filteredProducts.slice((this.productPage-1)*5,this.productPage*5)},
    get productPageNumbers(){const start=Math.min(Math.max(1,this.productPage-2),Math.max(1,this.totalProductPages-4));return Array.from({length:Math.min(5,this.totalProductPages-start+1)},(_,i)=>start+i)},
    setProductPage(page){
        const changed=this.productPage!==page;
        this.productPage=page;
        if(changed && !window.matchMedia('(prefers-reduced-motion: reduce)').matches){
            this.$nextTick(()=>this.$refs.productRows?.animate([{opacity:.6,transform:'translateY(3px)'},{opacity:1,transform:'translateY(0)'}],{duration:160,easing:'cubic-bezier(0.22, 1, 0.36, 1)'}));
        }
    },
    clearMember(){this.member=null;this.redemption=null;this.redeemPoints=0;this.results=[];this.memberMessage='';this.searching=false;this.searchRequest++},
    selectMember(result){this.member=result;this.results=[];this.memberMessage=''},
    async lookup(){
        const query=this.memberQuery.trim();this.member=null;this.redemption=null;this.redeemPoints=0;this.results=[];this.memberMessage='';
        if(query.length<3){this.memberMessage='Masukkan minimal 3 karakter.';return}
        const request=++this.searchRequest;this.searching=true;
        try{const response=await fetch(@js(route('integration.member'))+'?q='+encodeURIComponent(query),{headers:{'Accept':'application/json'}});
            if(!response.ok)throw Error('Pencarian member gagal. Coba lagi.');
            const data=await response.json();if(request!==this.searchRequest)return;
            this.results=data.members||[];this.redemption=data.redemption||null;if(!this.results.length)this.memberMessage='Member tidak ditemukan di Tanda.';
        }catch(error){if(request===this.searchRequest)this.memberMessage=error.message}
        finally{if(request===this.searchRequest)this.searching=false}
    },
    submitSale(event){
        this.submitError='';
        if(this.paymentProblem){event.preventDefault();this.submitError=this.paymentProblem;return}
        this.submitting=true;
    },
    get selectedCount(){return this.products.reduce((n,p)=>n+(Number(this.qty[p.id])||0),0)},
    get subtotal(){return this.products.reduce((n,p)=>n+(Number(this.qty[p.id])||0)*Number(p.price),0)},
    get eligibleSubtotal(){return this.products.reduce((n,p)=>n+(p.point_eligible?(Number(this.qty[p.id])||0)*Number(p.price):0),0)},
    get redeemValue(){return (Number(this.redeemPoints)||0)*Number(this.redemption?.redeem_value||0)},
    get appliedDiscount(){return this.discountEnabled ? Number(this.discount)||0 : 0},
    get total(){return Math.max(0,this.subtotal-this.appliedDiscount-this.redeemValue)},
    get change(){return Math.max(0,(Number(this.received)||0)-this.total)},
    get dynamicQris(){return this.midtransReady && this.method==='qris'},
    get paymentProblem(){
        if(this.method==='cash' && this.cashShiftEnforced && !this.cashShiftOpen)return 'Buka shift kasir sebelum menerima pembayaran tunai.';
        if(!this.selectedCount)return 'Pilih minimal satu produk sebelum mencatat pembayaran.';
        if(this.products.some(p=>Number(this.qty[p.id]||0)>Number(p.quantity) || Number(this.qty[p.id]||0)<0 || !Number.isInteger(Number(this.qty[p.id]||0))))return 'Jumlah produk harus sesuai stok yang tersedia.';
        if(this.appliedDiscount<0 || this.appliedDiscount>this.subtotal)return 'Diskon tidak boleh melebihi subtotal.';
        if(Number(this.redeemPoints)>0){
            if(!this.member||!this.redemption)return 'Pilih member dengan aturan penukaran poin aktif.';
            if(!Number.isInteger(Number(this.redeemPoints)))return 'Jumlah poin harus bilangan bulat.';
            if(Number(this.redeemPoints)<Number(this.redemption.minimum_redeem))return `Minimal ${this.redemption.minimum_redeem} poin untuk ditukar.`;
            if(Number(this.redeemPoints)>Number(this.member.point_balance))return 'Saldo poin member tidak cukup.';
            if(!['owner','manager'].includes(this.operatorRole) && Number(this.redeemPoints)>=Number(this.redemption.approval_threshold))return 'Penukaran ini harus diproses oleh manager atau owner.';
            const limit=Math.floor(Math.max(0,this.eligibleSubtotal-this.appliedDiscount)*Number(this.redemption.maximum_redeem_percent)/100);
            if(this.redeemValue>limit)return 'Penukaran melebihi batas nilai produk yang menghasilkan poin.';
        }
        if(this.dynamicQris && this.total<1)return 'QRIS memerlukan total minimal Rp1.';
        if(!this.dynamicQris && Number(this.received)<this.total)return 'Jumlah diterima kurang dari total pembayaran.';
        return '';
    },
    rupiah(n){return 'Rp'+Math.max(0,Number(n)||0).toLocaleString('id-ID')}
}}
</script>
@endsection
