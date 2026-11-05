<a href="{{ route('dashboard') }}" class="brand d-flex align-items-center gap-2" aria-label="Nodo Control, ir al resumen">
    <span class="brand-mark"><i class="bi bi-hdd-network" aria-hidden="true"></i></span>
    <span class="brand-name">Nodo Control</span>
</a>
<nav class="nav flex-column app-nav" aria-label="Navegación principal">
    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"><i class="bi bi-grid"></i><span>Resumen</span></a>
    <a href="{{ route('devices.index') }}" class="nav-link {{ request()->routeIs('devices.*') ? 'active' : '' }}"><i class="bi bi-pc-display-horizontal"></i><span>Equipos</span></a>
    <a href="{{ route('activity.index') }}" class="nav-link {{ request()->routeIs('activity.*') ? 'active' : '' }}"><i class="bi bi-clock-history"></i><span>Actividad</span></a>
    <a href="{{ route('settings.index') }}" class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}"><i class="bi bi-gear"></i><span>Configuración</span></a>
</nav>
<div class="sidebar-bottom mt-auto">
    <div class="sidebar-user d-flex align-items-center gap-2">
        <div class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
        <div class="flex-grow-1 min-w-0"><div class="text-truncate text-white fw-medium small">{{ auth()->user()->name }}</div><div class="text-truncate user-role">Sesión activa</div></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button title="Cerrar sesión" aria-label="Cerrar sesión" class="btn btn-logout" type="submit"><i class="bi bi-box-arrow-right"></i></button></form>
    </div>
</div>
