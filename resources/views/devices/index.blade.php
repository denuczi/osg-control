@extends('layouts.app')
@section('title', 'Equipos')
@section('content')
<div class="page-header"><h1>Equipos</h1><p>Computadoras registradas en la red, identificadas de forma persistente.</p></div>
<section class="surface-panel">
    <div class="panel-heading flex-wrap gap-3"><div><h2>Dispositivos autorizados</h2><p>{{ $devices->total() }} {{ $devices->total() === 1 ? 'equipo registrado' : 'equipos registrados' }}</p></div>
        <form action="{{ route('devices.index') }}" method="GET" class="search-form"><label class="visually-hidden" for="q">Buscar equipo</label><i class="bi bi-search"></i><input type="search" id="q" name="q" value="{{ $search }}" placeholder="Buscar por nombre o hostname"><button type="submit" class="btn btn-search">Buscar</button></form>
    </div>
    <div class="table-responsive"><table class="table clean-table align-middle mb-0"><thead><tr><th>Equipo</th><th>Estado</th><th>Sistema</th><th>CPU</th><th>Memoria</th><th>Último contacto</th><th class="text-end">Acción</th></tr></thead><tbody>
        @forelse($devices as $device)
        <tr><td><div class="device-identity"><div class="device-icon"><i class="bi bi-pc-display"></i></div><div><a class="primary-cell" href="{{ route('devices.show', $device) }}">{{ $device->name }}</a><div class="sub-cell">{{ $device->hostname }}</div></div></div></td><td>@include('partials.status')</td><td class="text-secondary cell-small">{{ $device->platform }}</td><td>{{ $device->isOnline() && $device->cpu_percent !== null ? number_format($device->cpu_percent, 0).'%' : '—' }}</td><td>{{ $device->isOnline() && $device->memory_percent !== null ? number_format($device->memory_percent, 0).'%' : '—' }}</td><td class="cell-small text-secondary">{{ ($device->last_seen_at ? app(\App\Services\DisplayTimezone::class)->human($device->last_seen_at) : 'Nunca') }}</td><td class="text-end"><a href="{{ route('devices.show', $device) }}" class="btn btn-table" aria-label="Abrir {{ $device->name }}"><i class="bi bi-arrow-up-right"></i></a></td></tr>
        @empty
        <tr><td colspan="7" class="text-center p-5"><div class="empty-icon mx-auto"><i class="bi bi-pc-display"></i></div><strong>{{ $search ? 'No hay coincidencias' : 'No hay equipos registrados' }}</strong><p class="text-secondary small mb-0">{{ $search ? 'Probá con otro nombre o hostname.' : 'Registrá el primer equipo mediante el agente Python.' }}</p></td></tr>
        @endforelse
    </tbody></table></div>
    @if($devices->hasPages())<div class="p-3 border-top">{{ $devices->links('pagination::bootstrap-5') }}</div>@endif
</section>
@endsection
