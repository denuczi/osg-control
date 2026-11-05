<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>OSG Nodo Control · Informe de estado</title>
<style>
@page { size: A4; margin: 20mm 18mm 20mm; @bottom-left { content: 'OSG NODO CONTROL  ·  INFORME DE DISPOSITIVO'; color:#64748b; font-size:8pt; } @bottom-right { content: 'Página ' counter(page) ' de ' counter(pages); color:#64748b; font-size:8pt; } }
* { box-sizing: border-box; } body { margin:0; color:#142337; font:10pt/1.45 'DejaVu Sans', sans-serif; }
h1 {font-size:25pt;line-height:1.15;margin:12mm 0 3mm;color:#10283d;} h2 {font-size:16pt;color:#123b59;margin:0 0 6mm;border-bottom:1pt solid #bac8d6;padding-bottom:3mm;} h3 {font-size:11pt;margin:6mm 0 2mm;color:#0e5277;}
p {margin:2mm 0 4mm;} .eyebrow {font-size:8pt;color:#2a6b80;letter-spacing:1.5pt;font-weight:700;text-transform:uppercase;}
.lead {font-size:11pt;color:#475569; margin-bottom:8mm;} .meta {font-size:9pt;color:#64748b;}
.cover {border-top:5pt solid #123b59; padding-top:5mm; min-height:70mm;}
.kpis {display:table;width:100%;border-spacing:2mm;margin:4mm 0 8mm;} .kpi {display:table-cell;background:#edf4f7;padding:4mm;border-radius:3mm;width:33%;}
.kpi small {font-size:8pt;display:block;color:#587085;} .kpi strong {font-size:15pt;color:#113a59;}
.section {page-break-before:always;} .keep {break-inside:avoid;} .muted {color:#64748b;}
.table {width:100%;border-collapse:collapse;font-size:9pt;table-layout:fixed;} .table th {text-align:left;background:#eaf0f5;color:#234967;font-weight:600;}
.table th,.table td {padding:2.5mm 3mm;border-bottom:.5pt solid #dde5ed;vertical-align:top;overflow-wrap:anywhere;word-wrap:break-word;}
.table td:first-child {color:#496176;width:43%;} .table tr {break-inside:avoid;}
.panel {border:0.6pt solid #dae3ea;border-radius:3mm;padding:4mm;margin-bottom:4mm;break-inside:avoid;}
.status {display:inline-block;padding:1mm 3mm;background:#e9f0f7;color:#245879;border-radius:3mm;font-size:8pt;}
.notice {background:#f0f4f7;padding:4mm;border-left:3pt solid #477d97;margin:5mm 0 7mm;font-size:9pt;}
.grid {display:table;width:100%;border-spacing:2mm;} .grid > div {display:table-cell;width:50%;vertical-align:top;}
.chart {width:100%;margin:3mm 0 7mm;page-break-inside:avoid;} .chart svg {display:block;width:100%;height:auto;}
.volume {margin:4mm 0 5mm;break-inside:avoid;} .volume-title {font-weight:700;} .bar {height:5mm;background:#e4eaf0;border-radius:2.5mm;margin:2mm 0;} .bar span {height:5mm;display:block;background:#317a99;border-radius:2.5mm;}
.network-interface { break-inside: avoid; margin: 4mm 0; }
.event-entry { border-top:.8pt solid #d5e0e7; padding:3mm 0 4mm; }
.event-heading {display:flex;align-items:center;gap:3mm;justify-content:space-between;}
.event-heading strong {font-size:9.3pt;color:#123b59}
.event-meta{font-size:8pt;color:#667785;margin-top:1mm}
.event-message {font-size:8.6pt;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;word-wrap:break-word;margin:2mm 0 0}
.network-note { color:#64748b; font-size:8.5pt; }
.academic-title {font: bold 12pt 'DejaVu Serif',serif;text-align:center;margin-bottom:12mm;border:0;color:#111;}
.academic {font:11pt/2 'DejaVu Serif',serif;color:#111;text-indent:12mm;text-align:left;}
</style>
</head>
<body>
@php
  $device = $report['device']; $meta = $report['meta']; $trafficZone = $meta['network_aggregation_timezone'] ?? ($meta['display_timezone'] ?? 'UTC'); $inventory = $report['inventory']; $diag = $report['diagnostic'];
  $metrics = $report['metrics']; $events = $report['system_events']; $volumes = $inventory['volumes'] ?? [];
  $hardware = data_get($diag, 'hardware.status') === 'ok' ? (data_get($diag, 'hardware.data') ?? []) : [];
  $disks = data_get($diag, 'disks.status') === 'ok' ? (data_get($diag, 'disks.data.drives') ?? []) : [];
  $security = data_get($diag, 'security.status') === 'ok' ? (data_get($diag, 'security.data') ?? []) : [];
  $fmt = static fn ($value) => $value === null || $value === '' ? 'No disponible' : (is_bool($value) ? ($value ? 'Sí' : 'No') : (is_scalar($value) ? (string)$value : 'No disponible'));
  $gib = static fn ($bytes) => is_numeric($bytes) ? number_format((float)$bytes / 1073741824, 2, ',', '.').' GiB' : 'No disponible';
  $windowsSupport = $report['windows_support'] ?? []; 
  $daily = $metrics['daily_local'] ?? $metrics['daily'] ?? [];
  $hourly = $metrics['hourly'] ?? [];
  $network = $presenter->network($inventory['network'] ?? null);
@endphp
<div class="cover">
 <div class="eyebrow">OSG / NODO CONTROL</div>
 <h1>Informe de estado<br>del dispositivo</h1>
 <p class="lead">Evaluación de rendimiento, inventario, almacenamiento, seguridad y conectividad.</p>
 <table class="table"><tr><td>Equipo</td><td><strong>{{ $device['name'] }}</strong></td></tr><tr><td>Nombre de host</td><td>{{ $device['hostname'] }}</td></tr>
 <tr><td>Sistema operativo</td><td>{{ $device['platform'] }}</td></tr>
 <tr><td>Fecha de emisión</td><td>{{ $presenter->dateTime($meta['captured_at']) }}</td></tr>
 <tr><td>Período analizado</td><td>{{ $presenter->period($meta['period_start'], $meta['period_end']) }}</td></tr>
 <tr><td>Última comunicación</td><td>{{ $presenter->dateTime($device['last_seen_at']) }}</td></tr>
 <tr><td>Horario de referencia</td><td>{{ $presenter->timezoneLabel($meta['captured_at']) }}</td></tr></table>
</div>
<h3>Indicadores principales</h3>
<div class="kpis"><div class="kpi"><small>Última CPU</small><strong>{{ $presenter->percent($device['cpu_percent']) }}</strong></div><div class="kpi"><small>Última RAM</small><strong>{{ $presenter->percent($device['memory_percent']) }}</strong></div><div class="kpi"><small>Muestras de 7 días</small><strong>{{ number_format($metrics['samples'] ?? 0,0,',','.') }}</strong></div></div>
<div class="notice"><strong>Interpretación:</strong> los valores reflejan mediciones del agente y la información informada por Windows. La ausencia de datos o eventos no certifica el buen estado de los componentes.</div>
@if(($windowsSupport['family'] ?? '') === 'Windows 10')
<div class="notice"><strong>Soporte de Windows:</strong> {{ $windowsSupport['summary'] }} {{ $windowsSupport['commercial_esu_summary'] }}</div>
@elseif(preg_match('/Windows\s+10\b/i', (string) ($device['platform'] ?? '')))
<div class="notice"><strong>Soporte de Windows:</strong> Windows 10 finalizó su soporte general en octubre de 2025; esta captura no identifica edición ni compilación para evaluar excepciones LTSC y ESU. No se puede confirmar la cobertura de parches.</div>
@endif

<div class="section">
<h2>01 / Rendimiento de los últimos siete días</h2>
<p class="meta">{{ $chartMaker->description($metrics) }} Muestras recibidas: {{ $metrics['samples'] ?? 0 }}. Cobertura estimada: {{ $presenter->percent($metrics['coverage_estimate_percent'] ?? null, 1) }}.</p>
@if($hourly)
<h3>Uso del procesador</h3>
<div class="chart">{!! $chartMaker->hourly($hourly, 'cpu_avg', '#2b668c', $meta['period_end'], $meta['display_timezone']) !!}</div>
<h3>Uso de memoria RAM</h3>
<div class="chart">{!! $chartMaker->hourly($hourly, 'ram_avg', '#178776', $meta['period_end'], $meta['display_timezone']) !!}</div>
<h3>Resumen diario</h3>
<table class="table"><thead><tr><th>Fecha (Argentina)</th><th>CPU media</th><th>RAM media</th><th>Muestras</th></tr></thead><tbody>
@foreach($daily as $day)<tr><td>{{ $presenter->calendarDay($day['time']) }}</td><td>{{ $presenter->percent($day['cpu_avg'], 1) }}</td><td>{{ $presenter->percent($day['ram_avg'], 1) }}</td><td>{{ $day['samples'] }}</td></tr>@endforeach
</tbody></table>
@else <p class="notice">No existen mediciones históricas suficientes para graficar el período.</p> @endif
<p class="meta">Percentil 95 aproximado (histograma 1 %): CPU {{ $fmt($metrics['cpu_p95_approx']) }} %; RAM {{ $fmt($metrics['ram_p95_approx']) }} %. Intervalos sin observación superiores a tres frecuencias esperadas: {{ $metrics['gaps_over_3_intervals'] }}. Frecuencia configurada: {{ $meta['interval_seconds'] }} s.</p>
</div>

<div class="section">
<h2>02 / Equipo, procesador y memoria</h2>
<p class="meta">Datos de inventario y diagnóstico SMBIOS. Última consulta: {{ $presenter->dateTime($diag['captured_at'] ?? null) }}.</p>
<table class="table">
@foreach(['Manufacturer'=>'Fabricante','Model'=>'Modelo','Board'=>'Placa madre','BiosVersion'=>'BIOS','Serial'=>'Número de serie','MemorySlots'=>'Ranuras RAM declaradas'] as $key=>$label)
<tr><td>{{ $label }}</td><td>{{ $fmt($hardware[$key] ?? null) }}</td></tr>
@endforeach
<tr><td>RAM instalada</td><td>{{ $gib($device['memory_total_bytes']) }}</td></tr>
</table>
<h3>Procesador</h3>
@if(is_array($inventory['processor'] ?? null))<table class="table">@foreach($presenter->processor($inventory['processor']) as $label => $value)<tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>@endforeach</table>@else<p>Información del procesador no disponible.</p>@endif
<h3>Módulos RAM</h3>
@forelse(($hardware['MemoryModules'] ?? []) as $module)
<div class="panel"><strong>{{ $fmt($module['Slot'] ?? null) }}</strong> · {{ $gib($module['CapacityBytes'] ?? null) }}<br><span class="muted">{{ $fmt($module['SpeedMHz'] ?? null) }} MHz · {{ $fmt($module['Manufacturer'] ?? null) }}</span></div>
@empty <p>No se informaron módulos.</p> @endforelse
<h3>Batería</h3>
@if(($hardware['BatteryPresent'] ?? null) === true)
@php $design = $hardware['BatteryDesignCapacity'] ?? null; $full = $hardware['BatteryFullChargeCapacity'] ?? null; $health = $design > 0 && $full !== null ? round(min(100, $full/$design * 100)) : null; @endphp
<table class="table"><tr><td>Carga actual</td><td>{{ $presenter->percent($hardware['BatteryPercent'] ?? null) }}</td></tr><tr><td>Salud estimada</td><td>{{ $health === null ? 'No disponible' : $health.' %' }}</td></tr><tr><td>Capacidad de diseño</td><td>{{ $fmt($design) }}</td></tr><tr><td>Capacidad de carga completa</td><td>{{ $fmt($full) }}</td></tr><tr><td>Ciclos informados</td><td>{{ $fmt($hardware['BatteryCycles'] ?? null) }}</td></tr></table>
@elseif(($hardware['BatteryPresent'] ?? null) === false)
<p>Este equipo no tiene batería detectada.</p>
@else<p>Estado de batería no disponible.</p>@endif
</div>

<div class="section">
<h2>03 / Almacenamiento</h2>
<p class="meta">Se distinguen las unidades físicas de los volúmenes lógicos; no se infiere una vinculación entre ambas fuentes.</p>
<h3>Volúmenes y capacidad</h3>
@forelse($volumes as $volume)
@php $total = $volume['total_bytes'] ?? 0; $used = $volume['used_bytes'] ?? null; $percent = $total > 0 && is_numeric($used) ? max(0,min(100,round($used/$total*100))) : null; @endphp
<div class="volume"><div class="volume-title">{{ $fmt($volume['mountpoint'] ?? null) }} · {{ filled($volume['label'] ?? null) ? $volume['label'] : 'Sin etiqueta' }}</div>
<div class="meta">{{ $fmt($volume['filesystem'] ?? null) }} · Usado: {{ $gib($used) }} · Total: {{ $gib($total) }}</div>
@if($percent !== null)<div class="bar"><span style="width:{{ $percent }}%"></span></div><div class="meta">{{ $percent }} % utilizado</div>@endif</div>
@empty<p>No se registraron volúmenes.</p>@endforelse
<h3>Salud de unidades físicas</h3>
<p class="meta">Temperatura y demás sensores: última consulta {{ $presenter->dateTime($diag['captured_at'] ?? null) }}. Son lecturas comunicadas por el controlador, no temperaturas promedio ni mediciones continuas; valores iguales en informes sucesivos no prueban que el sensor se actualice.</p>
@forelse($disks as $disk)
<div class="panel"><strong>{{ $fmt($disk['Name'] ?? null) }}</strong> <span class="status">{{ $presenter->health($disk['Health'] ?? null) }}</span>
<table class="table"><tr><td>Medio / tipo</td><td>{{ $presenter->mediaType($disk['MediaType'] ?? null) }}</td></tr><tr><td>Temperatura</td><td>{{ ($disk['Temperature'] ?? null) === null || (float)$disk['Temperature'] === 0.0 ? 'No disponible' : $disk['Temperature'].' °C' }}</td></tr>
<tr><td>Desgaste informado</td><td>{{ $presenter->percent($disk['Wear'] ?? null) }}</td></tr><tr><td>Horas de funcionamiento</td><td>{{ $fmt($disk['PowerOnHours'] ?? null) }}</td></tr><tr><td>Errores de lectura/escritura</td><td>{{ $fmt($disk['ReadErrors'] ?? null) }} / {{ $fmt($disk['WriteErrors'] ?? null) }}</td></tr></table></div>
@empty<p>No existen indicadores de salud de discos disponibles.</p>@endforelse
</div>

<div class="section">
<h2>04 / Protección y eventos de Windows</h2>
<h3>Versión y ciclo de soporte del sistema</h3>
<table class="table">
<tr><td>Edición y versión</td><td>{{ $presenter->value($windowsSupport['caption'] ?? null) }} · {{ $presenter->value($windowsSupport['release'] ?? null) }}</td></tr>
<tr><td>Compilación y revisión</td><td>{{ isset($windowsSupport['build']) ? $windowsSupport['build'].'.'.($windowsSupport['ubr'] ?? '—') : 'No disponible' }}</td></tr>
<tr><td>Canal de mantenimiento</td><td>{{ $presenter->value($windowsSupport['policy'] ?? null) }}</td></tr>
<tr><td>Fin del soporte publicado</td><td>{{ $presenter->calendarDay($windowsSupport['support_end'] ?? null) }}</td></tr>
<tr><td>Evaluación</td><td>{{ $presenter->value($windowsSupport['summary'] ?? null) }}</td></tr>
<tr><td>Programa ESU comercial</td><td>{{ $presenter->value($windowsSupport['commercial_esu_summary'] ?? null) }}</td></tr>
</table>
<p class="meta">La detección de licencias no certifica que se hayan instalado parches; las inscripciones ESU de consumo requieren verificación independiente. La fecha de soporte corresponde a la edición y compilación identificadas.</p>

<p class="meta">Estado comunicado por Windows. No sustituye una auditoría especializada.</p>
@if(($security['ProtectionQueryStatus'] ?? null) === 'unavailable')
<p class="notice">La consulta de protección de Windows no aportó indicadores verificables en esta captura. La versión del sistema se obtuvo por separado. No debe interpretarse como antivirus desactivado ni ausencia de firewall.</p>
@endif
@if($security)
<table class="table"><tr><td>Microsoft Defender</td><td>{{ $fmt($security['DefenderMode'] ?? null) }}</td></tr>
<tr><td>Protección en tiempo real</td><td>{{ $presenter->state($security['DefenderRealtime'] ?? null, 'Activa', 'Inactiva') }}</td></tr>
<tr><td>Antivirus registrados</td><td>{{ implode(', ', (array)($security['AntivirusProviders'] ?? [])) ?: 'No disponible' }}</td></tr>
<tr><td>Antigüedad de firmas</td><td>{{ is_numeric($security['DefenderSignatureAge'] ?? null) ? $presenter->number($security['DefenderSignatureAge']).' días' : 'No disponible' }}</td></tr>
<tr><td>Actualizaciones pendientes (caché)</td><td>{{ $fmt($security['WindowsUpdatesPending'] ?? null) }}</td></tr>
@foreach(($security['FirewallProfiles'] ?? []) as $profile)<tr><td>Firewall {{ $presenter->firewallProfile($profile['Name'] ?? null) }}</td><td>{{ $presenter->state($profile['Enabled'] ?? null) }}</td></tr>@endforeach
</table>
@else <p>Estado de seguridad no disponible.</p> @endif
<h3>Registro de eventos seleccionados</h3>
@php $shutdown = $report['shutdown_activity'] ?? []; @endphp
@if($shutdown)
<div class="notice"><strong>Apagados y reinicios:</strong>
{{ $shutdown['requested_restarts_or_shutdowns'] }} solicitudes registradas (ID 1074),
{{ $shutdown['shutdown_started_signals'] }} inicios de apagado (ID 13),
{{ $shutdown['clean_shutdown_signals'] }} cierres ordenados registrados (ID 6006) y
{{ $shutdown['unclean_incidents'] }} posibles cierres no limpios (IDs 41/6008 agrupados cuando corresponden al mismo incidente).
Una solicitud del usuario no confirma que el cierre terminara correctamente. Un cierre no limpio tampoco determina por sí solo un fallo físico.
@if($shutdown['recent_truncated'] ?? false) Resumen limitado a los registros recientes disponibles. @endif
</div>
@endif
<p class="meta">{{ $events['total'] }} registros recibidos durante el período. Se presenta la descripción original de Windows cuando el agente la proporciona, incluidos los procesos, usuarios y rutas mencionados por el evento. La captura sigue limitada a un catálogo de eventos; no equivale al registro completo.</p>
@if(count($events['distribution'] ?? []) > 0)
<h3>Tipos de eventos más frecuentes</h3>
<table class="table"><thead><tr><th>Evento</th><th>Proveedor</th><th>Veces</th></tr></thead><tbody>
@foreach(array_slice($events['distribution'], 0, 8) as $type)
<tr><td>{{ $presenter->eventTitle($type) }} (ID {{ $type['event_id'] }})</td><td>{{ $presenter->value($type['provider'] ?? null) }}</td><td>{{ $type['total'] }}</td></tr>
@endforeach
</tbody></table>
@endif
<h3>Detalle de los últimos registros</h3>
@forelse(array_slice($events['recent'], 0, 12) as $event)
<div class="event-entry keep">
  <div class="event-heading"><strong>{{ $presenter->eventTitle($event) }}</strong><span class="status">{{ $presenter->eventSeverity($event['level'] ?? null) }}</span></div>
  <div class="event-meta">{{ $presenter->shortDateTime($event['observed_at']) }} h · {{ $presenter->eventChannel($event['channel'] ?? null) }} · ID {{ $event['event_id'] }} · Registro {{ $event['record_id'] }}</div>
  <div class="event-meta">Proveedor: {{ $presenter->value($event['provider'] ?? null) }}@if(filled($event['user_sid'] ?? null)) · Usuario de Windows (SID): {{ $event['user_sid'] }}@endif</div>
  <p class="event-message">{{ $presenter->eventDetail($event['message'] ?? null) }}</p>
  @if(is_string($event['message'] ?? null) && str_contains($event['message'], "\u{FFFD}"))
  <p class="meta">El texto recibido contiene caracteres dañados en origen; será necesario volver a obtener el evento para recuperar su descripción.</p>
  @endif
</div>
@empty
<p>No hay eventos de las categorías monitoreadas en este período.</p>
@endforelse
@if(($events['total'] ?? 0) > 12)
<p class="meta">Se muestran 12 de {{ $events['total'] }} registros; los demás permanecen almacenados en el servidor según la política de retención.</p>
@endif
</div>

<div class="section">
<h2>05 / Conectividad y actividad</h2>
<p class="meta">Información de red e historial de actividad recopilados desde el agente.</p>
<h3>Configuración general de red</h3>
@if($network !== null)
<table class="table">
<tr><td>Puerta de enlace predeterminada</td><td>{{ $network['gateway'] }}</td></tr>
<tr><td>Servidores DNS</td><td>{{ $network['dns'] }}</td></tr>
@if($network['ssid'] !== 'No disponible')<tr><td>Nombre de la red Wi-Fi</td><td>{{ $network['ssid'] }}</td></tr>@endif
</table>
<h3>Adaptadores de red</h3>
@forelse($network['interfaces'] as $interface)
<div class="panel network-interface">
<strong>{{ $interface['name'] }}</strong> <span class="status">{{ $interface['status'] }}</span>
<table class="table">
<tr><td>Velocidad del enlace</td><td>{{ $interface['speed'] }}</td></tr>
<tr><td>Dirección IPv4</td><td>{{ $interface['ipv4'] }}</td></tr>
<tr><td>Dirección IPv6</td><td>{{ $interface['ipv6'] }}</td></tr>
<tr><td>Dirección física (MAC)</td><td>{{ $interface['mac'] }}</td></tr>
</table></div>
@empty<p>No se informaron adaptadores de red.</p>@endforelse
@if($network['interfaces_total'] > count($network['interfaces']))
<p class="network-note">Se muestran {{ count($network['interfaces']) }} de {{ $network['interfaces_total'] }} adaptadores.</p>
@endif
@if($network['profiles_total'] > 0)
<h3>Redes reconocidas por Windows</h3>
<table class="table"><thead><tr><th>Red</th><th>Adaptador y tipo de red</th><th>Conectividad</th></tr></thead><tbody>
@foreach($network['profiles'] as $profile)
<tr><td>{{ $profile['name'] }}</td><td>{{ $profile['interface'] }} · {{ $profile['category'] }}</td><td>{{ $profile['connectivity'] }}</td></tr>
@endforeach
</tbody></table>
@endif
@if($network['neighbors_total'] > 0)
<h3>Otros dispositivos conocidos por Windows</h3>
<p class="network-note">{{ $network['neighbors_total'] }} entradas registradas en la tabla de vecinos. No representa un escaneo completo de la red.</p>
<table class="table"><thead><tr><th>Dirección IP</th><th>Dirección física (MAC)</th><th>Estado registrado</th></tr></thead><tbody>
@foreach($network['neighbors'] as $neighbor)
<tr><td>{{ $neighbor['ip'] }}</td><td>{{ $neighbor['mac'] }}</td><td>{{ $neighbor['state'] }}</td></tr>
@endforeach
</tbody></table>
@if($network['neighbors_total'] > count($network['neighbors']))
<p class="network-note">Se muestran {{ count($network['neighbors']) }} de {{ $network['neighbors_total'] }} entradas.</p>
@endif
@endif
@else<p>Información de conectividad no disponible.</p>@endif
<h3>Tráfico diario observado</h3>
<table class="table"><thead><tr><th>Día ({{ $trafficZone }})</th><th>Recibido</th><th>Enviado</th><th>Tiempo observado</th></tr></thead><tbody>
@forelse($report['network_usage_daily'] as $row)<tr><td>{{ $presenter->calendarDay($row['day']) }}</td><td>{{ $gib($row['rx_bytes']) }}</td><td>{{ $gib($row['tx_bytes']) }}</td><td>{{ $presenter->number(($row['observed_seconds'] ?? 0) / 3600, 1) }} h</td></tr>
@empty<tr><td colspan="4">No hay tráfico agregado para este período</td></tr>@endforelse
</tbody></table>
<h3>Eventos USB</h3>
<p>{{ $report['media_events']['total'] }} registros; conexiones, desconexiones y medios detectados.</p>
<h3>Órdenes remotas</h3>
<table class="table"><thead><tr><th>Acción</th><th>Estado</th><th>Cantidad</th></tr></thead><tbody>
@forelse($report['command_outcomes'] as $row)<tr><td>{{ $presenter->action($row['action'] ?? null) }}</td><td>{{ $presenter->commandStatus($row['status'] ?? null) }}</td><td>{{ $row['total'] }}</td></tr>
@empty<tr><td colspan="3">No se registraron órdenes en el período</td></tr>@endforelse
</tbody></table>
<p class="meta">Los acumulados diarios de tráfico corresponden a la zona {{ $trafficZone }}. Se reconstruyen desde mediciones horarias cuando están disponibles; de lo contrario se conserva la agrupación original y se identifica su zona. Las demás fechas se muestran en el horario indicado en la primera página.</p>
<p class="meta">Información recopilada del inventario y las mediciones registradas del equipo. Las mediciones faltantes o desactualizadas no se completan con estimaciones.</p>
</div>
@if($conclusion)
<div class="section"><h2 class="academic-title">Conclusión y recomendaciones</h2>
@foreach(explode("\n\n", $conclusion) as $paragraph)<p class="academic">{{ $paragraph }}</p>@endforeach
</div>
@endif
</body>
</html>
