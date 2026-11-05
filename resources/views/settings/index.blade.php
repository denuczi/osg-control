@extends('layouts.app')
@section('title', 'Configuración')
@section('content')
<div class="page-header"><div><h1>Configuración</h1><p>Frecuencias de monitoreo y conservación de datos.</p></div></div>
<form method="POST" action="{{ route('settings.update') }}" class="surface-panel settings-surface">
    @csrf
    @method('PUT')
    <div class="panel-heading"><div><h2>Intervalos de actualización</h2><p>Los cambios se aplican automáticamente cuando cada agente vuelve a consultar al servidor.</p></div></div>
    <div class="settings-grid">
        @foreach([
            'metrics_interval_seconds' => ['Captura de métricas', 'Cada cuánto la computadora recopila y almacena CPU, RAM y disco.', 'segundos'],
            'dashboard_refresh_seconds' => ['Actualización del panel', 'Frecuencia de las consultas automáticas del navegador.', 'segundos'],
            'heartbeat_interval_seconds' => ['Señal de actividad', 'Comprobación ligera que informa que el agente está en ejecución.', 'segundos'],
            'command_poll_seconds' => ['Consulta de órdenes', 'Frecuencia con la que cada equipo busca instrucciones pendientes.', 'segundos'],
            'retention_days' => ['Retención del historial central', 'Tiempo antes de depurar las métricas históricas en Laravel.', 'días'],
        ] as $key => [$title, $help, $unit])
        <div class="settings-field">
            <label for="{{ $key }}" class="form-label fw-semibold">{{ $title }}</label>
            <div class="input-group"><input type="number" class="form-control @error($key) is-invalid @enderror" id="{{ $key }}" name="{{ $key }}" value="{{ old($key, $settings[$key]) }}" min="{{ $ranges[$key][0] }}" max="{{ $ranges[$key][1] }}" step="1" required><span class="input-group-text">{{ $unit }}</span></div>
            <small class="form-text d-block mt-2">{{ $help }} Rango: {{ $ranges[$key][0] }}–{{ $ranges[$key][1] }}.</small>
            @error($key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        @endforeach
    </div>
    <div class="settings-footer"><p>Las computadoras desconectadas conservan la última configuración recibida. La captura predeterminada es de 60 segundos.</p><button type="submit" class="btn btn-primary">Guardar configuración</button></div>
</form>
@endsection
