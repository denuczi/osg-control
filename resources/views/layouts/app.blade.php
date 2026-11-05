<!doctype html>
<html lang="es" data-color-scheme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="display-timezone" content="{{ app(\App\Services\DisplayTimezone::class)->current() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') — Nodo Control</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
<div class="workspace">
    <aside class="sidebar d-none d-lg-flex flex-column">@include('partials.navigation')</aside>
    <div class="offcanvas offcanvas-start sidebar mobile-sidebar" tabindex="-1" id="mobileSidebar" aria-labelledby="sidebarLabel">
        <div class="offcanvas-header"><h2 class="visually-hidden" id="sidebarLabel">Menú de navegación</h2><button type="button" class="btn-close ms-auto" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button></div>
        <div class="offcanvas-body d-flex flex-column p-0">@include('partials.navigation')</div>
    </div>
    <div class="main-shell">
        <header class="topbar d-flex align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <button class="btn topbar-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Abrir menú"><i class="bi bi-list"></i></button>
                <span class="topbar-page text-truncate">@yield('title', 'Resumen')</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="topbar-context d-none d-sm-inline">Red local</span>
                <button type="button" class="btn topbar-icon" id="refreshPage" aria-label="Actualizar datos" title="Actualizar datos"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
        </header>
        <main class="content" id="main-content">
            @if(session('success'))<div role="status" class="alert alert-success d-flex align-items-center gap-2 border-0"><i class="bi bi-check-circle"></i>{{ session('success') }}</div>@endif
            @if($errors->any())<div role="alert" class="alert alert-danger border-0"><strong>No se pudo completar la operación.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @if(\App\Models\Device::query()->where('is_demo', true)->exists())
                <div class="demo-notice" role="note"><i class="bi bi-info-circle" aria-hidden="true"></i><span><strong>Datos de demostración.</strong> Los equipos marcados como Demo son ficticios y no admiten órdenes remotas.</span></div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>
