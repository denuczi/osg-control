<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Iniciar sesión — Nodo Control</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="login-page">
<main class="login-wrap">
    <section class="login-intro">
        <div class="brand d-flex align-items-center gap-3"><div class="brand-mark"><i class="bi bi-hdd-network"></i></div><div><div class="brand-name">Nodo Control</div></div></div>
        <div class="login-headline"><h1>Administración de equipos</h1><p>Monitoreo y operaciones remotas en tu red local.</p></div>
        <div class="login-footer">Nodo Control</div>
    </section>
    <section class="login-form-section">
        <div class="login-form-card">
            <h2>Bienvenido</h2><p>Iniciá sesión para acceder a la consola.</p>
            <form method="POST" action="{{ route('login.attempt') }}">@csrf
                <div class="mb-3"><label for="email" class="form-label">Correo electrónico</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" class="form-control form-control-lg @error('email') is-invalid @enderror" required autofocus>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="mb-4"><label for="password" class="form-label">Contraseña</label><input id="password" type="password" name="password" autocomplete="current-password" class="form-control form-control-lg" required></div>
                <button class="btn btn-primary w-100 btn-lg" type="submit">Ingresar al panel <i class="bi bi-arrow-right ms-2"></i></button>
            </form>
            <div class="login-footnote"><i class="bi bi-lock"></i> No se permite el registro público. Las cuentas se administran desde el servidor.</div>
        </div>
    </section>
</main>
</body>
</html>
