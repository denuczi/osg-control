@extends('layouts.app')
@section('title', 'Resumen')
@section('content')
<div class="page-header d-flex flex-wrap justify-content-between align-items-end gap-3">
    <div><h1>Resumen</h1><p>Monitoreo y administración de equipos registrados.</p></div>
    <a href="{{ route('devices.index') }}" class="btn btn-primary"><i class="bi bi-pc-display-horizontal me-2"></i>Ver equipos</a>
</div>
<p class="small text-danger d-none" data-dashboard-sync-error role="status">No se pudo actualizar el estado de la red. Se muestran los últimos datos recibidos.</p>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="stat-panel"><span class="stat-label">Equipos registrados</span><div class="stat-value" data-kpi="total">{{ $devices->count() }}</div><div class="stat-foot">Registrados en el sistema</div></div></div>
    <div class="col-md-4"><div class="stat-panel"><span class="stat-label">Equipos disponibles</span><div class="stat-value"><span data-kpi="online">{{ $onlineCount }}</span> <span class="stat-secondary">/ {{ $devices->count() }}</span></div><div class="stat-foot">Incluye dispositivos Demo</div></div></div>
    <div class="col-md-4"><div class="stat-panel"><span class="stat-label">CPU promedio</span><div class="stat-value" data-kpi="average_cpu">{{ $avgCpu === null ? '—' : number_format($avgCpu, 1, ',', '.') . '%' }}</div><div class="stat-foot">Equipos con métricas recientes</div></div></div>
</div>
<div class="row g-4">
    <div class="col-xxl-8">
        <section class="surface-panel h-100" data-dashboard-url="{{ route('dashboard.status') }}">
            <div class="panel-heading"><div><h2>Equipos</h2><p>Estado y consumo actual por dispositivo.</p></div><a href="{{ route('devices.index') }}" class="text-link">Todos los equipos <i class="bi bi-arrow-right"></i></a></div>
            @if($devices->isEmpty())
                <div class="empty-state"><div class="empty-icon"><i class="bi bi-pc-display-horizontal"></i></div><h3>Todavía no hay equipos registrados</h3><p>Instalá el agente Python en una computadora y registralo con un código de un solo uso.</p><code>php artisan nodo:enroll-code</code></div>
            @else
            <div class="table-responsive"><table class="table clean-table align-middle mb-0"><thead><tr><th>Equipo</th><th>Estado</th><th>CPU</th><th>Memoria</th><th class="text-end">Acción</th></tr></thead><tbody>
                @foreach($devices as $device)
                <tr data-device-id="{{ $device->uuid }}"><td><div class="device-identity"><div class="device-icon"><i class="bi bi-pc-display"></i></div><div><a class="primary-cell" href="{{ route('devices.show', $device) }}">{{ $device->name }}</a><div class="sub-cell">{{ $device->hostname }}</div></div></div></td><td data-live-status>@include('partials.status')</td><td><span class="metric-cell" data-live-cpu>{{ $device->isOnline() && $device->cpu_percent !== null ? number_format($device->cpu_percent, 0).'%' : '—' }}</span></td><td><span class="metric-cell" data-live-memory>{{ $device->isOnline() && $device->memory_percent !== null ? number_format($device->memory_percent, 0).'%' : '—' }}</span></td><td class="text-end"><a class="btn btn-table" href="{{ route('devices.show', $device) }}" aria-label="Abrir {{ $device->name }}"><i class="bi bi-arrow-up-right"></i></a></td></tr>
                @endforeach
            </tbody></table></div>
            @endif
        </section>
    </div>
    <div class="col-xxl-4">
        <section class="surface-panel h-100">
            <div class="panel-heading"><div><h2>Órdenes recientes</h2><p>Historial de operaciones.</p></div></div>
            @forelse($recentCommands as $command)
                <div class="activity-item"><span class="activity-icon"><i class="bi {{ $command->action === 'terminate_process' ? 'bi-x-square' : 'bi-power' }}"></i></span><div class="activity-body"><strong>{{ ['terminate_process' => 'Cerrar proceso', 'shutdown' => 'Apagar equipo', 'restart' => 'Reiniciar equipo'][$command->action] ?? $command->action }}</strong><div class="sub-cell">{{ $command->device?->name ?? 'Equipo eliminado' }} · {{ app(\App\Services\DisplayTimezone::class)->human($command->created_at) }}</div></div>@include('partials.command_status')</div>
            @empty
                <div class="empty-mini"><i class="bi bi-clock-history"></i><p>No hay órdenes registradas todavía.</p></div>
            @endforelse
            @if($recentCommands->isNotEmpty())<a href="{{ route('activity.index') }}" class="text-link d-inline-block mt-3">Ver registro de actividad <i class="bi bi-arrow-right"></i></a>@endif
        </section>
    </div>
</div>
@endsection
