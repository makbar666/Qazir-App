<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Masuk · Kasir</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="app-body">
    <div class="login-shell motion-page">
        <div class="login-panel">
            <div class="login-brand"><span class="brand-mark">k<span>.</span></span><div><strong>kasir<span>.</span></strong><small>WORKSPACE</small></div></div>
            <div class="login-heading"><p class="eyebrow">AKSES RUANG KERJA</p><h1>Masuk ke akun Anda</h1><p>Gunakan akun yang diberikan pemilik bisnis.</p></div>
            @if($errors->any())<div class="app-error" role="alert">{{ $errors->first() }}</div>@endif
            <form action="{{ route('login') }}" method="post" class="login-form">@csrf
                <div><label class="label" for="login-email">Email</label><input id="login-email" type="email" name="email" value="{{ old('email') }}" class="field" autocomplete="username" required></div>
                <div><label class="label" for="login-password">Kata sandi</label><input id="login-password" type="password" name="password" class="field" autocomplete="current-password" required></div>
                <button class="btn btn-primary w-full">Masuk ke kasir <span aria-hidden="true">→</span></button>
            </form>
            <p class="login-footnote">Kasir · Sistem penjualan dan operasional outlet</p>
        </div>
    </div>
</body>
</html>
