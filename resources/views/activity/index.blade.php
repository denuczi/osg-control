@extends('layouts.app')
@section('title', 'Actividad')
@section('content')
<div class="page-header"><h1>Registro de actividad</h1><p>Historial de órdenes y cambios realizados por administradores y agentes.</p></div>
<section class="surface-panel"><div class="panel-heading"><div><h2>Eventos registrados</h2><p>Los registros permiten rastrear quién solicitó cada operación y su resultado.</p></div></div><div class="table-responsive"><table class="table clean-table align-middle mb-0"><thead><tr><th>Fecha y hora</th><th>Evento</th><th>Equipo</th><th>Actor</th><th>Detalle</th></tr></thead><tbody>
@forelse($logs as $log)
<tr><td class="cell-small text-secondary text-nowrap">{{ app(\App\Services\DisplayTimezone::class)->human($log->created_at) }}</td><td><span class="primary-cell">{{ ['device.renamed'=>'Equipo renombrado','command.created'=>'Orden creada','command.completed'=>'Orden ejecutada','command.failed'=>'Orden fallida','command.unknown'=>'Resultado sin confirmar','command.expired'=>'Orden vencida','settings.updated'=>'Configuración modificada'][$log->event] ?? $log->event }}</span></td><td>@if($log->device)<a href="{{ route('devices.show', $log->device) }}" class="text-link">{{ $log->device->name }}</a>@else—@endif</td><td class="cell-small">{{ $log->user?->name ?? 'Sistema' }}</td><td class="cell-small text-secondary">{{ $log->context['action'] ?? $log->context['name'] ?? $log->context['message'] ?? ($log->event === 'settings.updated' ? 'Frecuencias de monitoreo' : '—') }}</td></tr>
@empty<tr><td colspan="5" class="p-5 text-center text-secondary">Todavía no hay eventos registrados.</td></tr>@endforelse
</tbody></table></div>@if($logs->hasPages())<div class="p-3 border-top">{{ $logs->links('pagination::bootstrap-5') }}</div>@endif</section>
@endsection
