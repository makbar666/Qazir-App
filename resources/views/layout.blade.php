<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Ringkasan') · Kasir</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
@php
    $outletOptions = \App\Support\OutletContext::available(auth()->user());
    $activeOutletId = \App\Support\OutletContext::id(request());
    $roleDefinition = \App\Support\RoleAccess::definition(auth()->user());
    $allowedMenus = auth()->user()->role === 'owner' ? array_keys(\App\Support\RoleAccess::MENUS) : (json_decode($roleDefinition?->menus ?? '[]', true) ?: []);
    $sidebarGroups = [
        ['label' => 'Operasional', 'items' => [
            ['dashboard', 'dashboard', 'Ringkasan', 'RG'],
            ['checkout', 'checkout', 'Kasir', 'KS'],
            ['shifts', 'shifts', 'Shift kasir', 'SH'],
            ['sales', 'sales', 'Penjualan', 'PJ'],
        ]],
        ['label' => 'Inventaris', 'items' => [
            ['products', 'products', 'Produk & stok', 'PR'],
            ['transfers', 'transfers', 'Transfer stok', 'TF'],
            ['purchases', 'purchases', 'Pembelian', 'PB'],
            ['stock_alerts', 'stock_alerts', 'Stok menipis', 'SM'],
            ['stock_counts', 'stock_counts', 'Stok opname', 'OP'],
        ]],
        ['label' => 'Manajemen', 'items' => [
            ['report', 'report', 'Laporan', 'LP'],
            ['reconciliation', 'reconciliation', 'Rekonsiliasi', 'RK'],
            ['integration', 'integration', 'Integrasi Tanda', 'IT'],
            ['midtrans', 'midtrans.settings', 'QRIS Midtrans', 'QR'],
            ['outlets', 'outlets', 'Outlet', 'OT'],
            ['users', 'users', 'Pengguna', 'PG'],
            ['roles', 'roles', 'Master Peran', 'MP'],
        ]],
    ];
@endphp
<div x-data="{ menu: false, collapsed: localStorage.getItem('kasir-sidebar-collapsed') === '1' }" class="app-shell">
    <div class="sidebar-scrim" x-show="menu" x-transition.opacity.duration.150ms @click="menu=false" aria-hidden="true"></div>
    <aside :class="[menu ? 'is-mobile-open' : '', collapsed ? 'is-collapsed' : '']" class="app-sidebar">
        <div class="sidebar-brand">
            <a href="{{ route(\App\Support\RoleAccess::homeRoute(auth()->user())) }}" class="brand-mark" aria-label="Kasir, beranda">k<span>.</span></a>
            <div class="sidebar-brand-text"><strong>kasir<span>.</span></strong><small>WORKSPACE</small></div>
            <button type="button" class="sidebar-toggle" @click="collapsed=!collapsed; localStorage.setItem('kasir-sidebar-collapsed', collapsed ? '1' : '0')" :aria-label="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'" :title="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'" :aria-expanded="!collapsed"><span aria-hidden="true" x-text="collapsed ? '›' : '‹'"></span></button>
            <button type="button" class="sidebar-mobile-close" @click="menu=false" aria-label="Tutup menu">×</button>
        </div>
        <nav class="sidebar-scroll" aria-label="Navigasi utama">
            @foreach($sidebarGroups as $group)
                @php($visibleItems = collect($group['items'])->filter(fn ($item) => in_array($item[0], $allowedMenus, true) || (auth()->user()->role === 'owner' && in_array($item[0], ['users', 'roles'], true))))
                @if($visibleItems->isNotEmpty())
                    <div class="sidebar-group">
                        <div class="sidebar-heading">{{ $group['label'] }}</div>
                        @foreach($visibleItems as [$key, $route, $label, $abbreviation])
                            <a href="{{ route($route) }}" title="{{ $label }}" class="sidebar-link {{ request()->routeIs($route.'*') ? 'is-active' : '' }}" @if(request()->routeIs($route.'*')) aria-current="page" @endif><span class="sidebar-monogram" aria-hidden="true">{{ $abbreviation }}</span><span class="sidebar-label">{{ $label }}</span></a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </nav>
        <div class="sidebar-account"><span class="account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><div class="sidebar-account-text"><strong>{{ auth()->user()->name }}</strong><span>{{ $roleDefinition?->name ?? auth()->user()->role }}</span></div><form method="post" action="{{ route('logout') }}">@csrf<button class="sidebar-logout" aria-label="Keluar akun" title="Keluar akun">Keluar</button></form></div>
    </aside>
    <div class="app-content">
        <header class="app-topbar"><div class="topbar-leading"><button type="button" class="mobile-menu-button" @click="menu=true" aria-label="Buka menu">☰</button><span class="topbar-context">Ruang kerja</span><span class="topbar-separator">/</span><strong>@yield('title', 'Ringkasan')</strong></div><div class="topbar-actions">@if($outletOptions->count() > 1)<form method="post" action="{{ route('outlet.switch') }}">@csrf<label class="sr-only" for="active-outlet">Outlet aktif</label><select id="active-outlet" name="outlet_id" class="topbar-outlet" onchange="this.form.submit()">@foreach($outletOptions as $outlet)<option value="{{ $outlet->id }}" @selected($activeOutletId === $outlet->id)>{{ $outlet->name }}</option>@endforeach</select></form>@else<span class="topbar-outlet-name">{{ $outletOptions->first()?->name }}</span>@endif<span class="topbar-user">{{ auth()->user()->name }}</span></div></header>
        @if(session('success') || session('error'))
            <div class="flash-toast-stack">
                @if(session('success'))<div class="flash-toast flash-toast--success" role="status" x-data="{show:true}" x-init="setTimeout(() => show=false, 4500)" x-show="show" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2"><span class="flash-toast__icon" aria-hidden="true">✓</span><span class="flash-toast__message">{{ session('success') }}</span><button type="button" class="flash-toast__close" aria-label="Tutup pemberitahuan" @click="show=false">×</button></div>@endif
                @if(session('error'))<div class="flash-toast flash-toast--error" role="alert" x-data="{show:true}" x-init="setTimeout(() => show=false, 7000)" x-show="show" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2"><span class="flash-toast__icon" aria-hidden="true">!</span><span class="flash-toast__message">{{ session('error') }}</span><button type="button" class="flash-toast__close" aria-label="Tutup pemberitahuan" @click="show=false">×</button></div>@endif
            </div>
        @endif
        <main class="app-main">@if($errors->any())<div class="form-feedback app-error" role="alert"><strong>Periksa kembali:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif @yield('content')</main>
    </div>
</div>
</body>
</html>
