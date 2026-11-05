<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>OSG Nodo Control · Inventario consolidado de equipos</title>
<style>
@page {size: A4 landscape; margin: 15mm 14mm 18mm; @bottom-left {content:'OSG NODO CONTROL · INVENTARIO DE EQUIPOS';font-size:8pt;color:#66778a} @bottom-right {content:'Página ' counter(page) ' de ' counter(pages);font-size:8pt;color:#66778a}}
*{box-sizing:border-box}
body{font:9pt/1.46 'DejaVu Sans',sans-serif;color:#182b3f;margin:0}
h1{margin:4mm 0 1mm;font-size:22pt;line-height:1.2;color:#10283d}h2{font-size:13pt;margin:0 0 5mm;color:#13506e}p{margin:2mm 0 4mm}
.brand{border-top:4pt solid #123e5a;padding-top:4mm;color:#1f6683;font-weight:700;letter-spacing:1.3pt;font-size:9pt}
.lead{font-size:10pt;color:#5a6c7b}.meta{color:#5d6e7e;font-size:8.7pt;margin:5mm 0 7mm}
.kpis{width:100%;border-collapse:separate;border-spacing:2mm 0;margin:0 -2mm 6mm;table-layout:fixed}
.kpis td{background:#eef4f8;border:1px solid #e2ebf1;padding:3mm 3.4mm;border-radius:2.5mm;vertical-align:top}
.kpis small{display:block;font-size:7.4pt;color:#577084;margin-bottom:1.5mm}
.kpis strong{font-size:14pt;color:#125176}
.table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.3pt}
.table thead{display:table-header-group}.table th{background:#123e5a;color:white;text-align:left;padding:2.5mm 2mm;line-height:1.3}
.table td{border-bottom:.5pt solid #dfe7ed;padding:1.8mm 1.6mm;vertical-align:top;overflow-wrap:anywhere;word-wrap:break-word}
.table td.uid{font:6.1pt 'DejaVu Sans Mono',monospace;white-space:nowrap;overflow-wrap:normal;word-wrap:normal}.table tbody tr:nth-child(even){background:#f6f8fa}.table tr{break-inside:avoid}
.table .order{width:7mm}.table .name{width:26mm}.table .id{width:60mm}.table .os{width:36mm}.table .processor{width:51mm}.table .cores{width:17mm}.table .ram{width:17mm}.table .storage{width:21mm}.table .battery{width:15mm}
.small{font-size:7.6pt;color:#718193}.note{font-size:8pt;color:#5a6b78;margin:4mm 0 0;line-height:1.5}
.section{page-break-before:always}.section-heading{display:flex;justify-content:space-between;border-bottom:1pt solid #cfdae3;margin:3mm 0 5mm;padding-bottom:3mm}
.cards-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:2.5mm 3mm}.cards-table td{width:50%;vertical-align:top;break-inside:avoid}.device-card{border:.7pt solid #d9e3eb;border-radius:2mm;padding:3mm 3.5mm;break-inside:avoid;min-width:0;font-size:9pt}
.device-title{font-size:10pt;font-weight:700;color:#124d70;display:block;margin-bottom:1mm}
.device-sub{color:#6a7c8d;font-size:8pt;margin:0 0 2mm}
.facts{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8pt}
.facts td{border-top:.4pt solid #edf0f3;padding:1mm 0;vertical-align:top;overflow-wrap:anywhere}
.facts td:first-child{width:33%;color:#677787;padding-right:3mm}
.facts td:last-child{font-weight:600}
.uid{font-size:7.8pt;font-family:'DejaVu Sans Mono',monospace;overflow-wrap:anywhere;word-wrap:break-word}
</style>
</head>
<body>
@php
  $rows = $inventory['rows']; $summary = $inventory['summary'];
  $fmt = static fn($value) => $value === null || $value === '' ? 'No informado' : (string) $value;
  $gib = static fn($bytes) => $bytes === null ? 'No informado' : number_format($bytes / 1073741824, 1, ',', '.').' GiB';
  $average = static fn($a, $factor = 1, $unit = '') => $a['value'] === null ? 'No informado' : number_format($a['value'] / $factor, 1, ',', '.').$unit;
  $battery = static fn($b) => $b === true ? 'Sí' : ($b === false ? 'No' : 'No informado');
@endphp
<div class="brand">OSG / NODO CONTROL</div>
<h1>Inventario consolidado de equipos</h1>
<p class="lead">Resumen del parque informático registrado</p>
<p class="meta"><strong>Fecha de emisión:</strong> {{ $presenter->dateTime($inventory['generated_at']) }} · <strong>Equipos:</strong> {{ $inventory['count'] }} · <strong>Orden:</strong> núcleos físicos, RAM, hilos y frecuencia máxima declarada</p>
<table class="kpis"><tr>
<td><small>Equipos registrados</small><strong>{{ $inventory['count'] }}</strong></td>
<td><small>RAM promedio</small><strong>{{ $average($summary['ram'], 1073741824, ' GiB') }}</strong></td>
<td><small>Volúmenes declarados (promedio)</small><strong>{{ $average($summary['storage'], 1073741824, ' GiB') }}</strong></td>
<td><small>Núcleos físicos promedio</small><strong>{{ $average($summary['physical_cores']) }}</strong></td>
<td><small>Frecuencia máxima declarada promedio</small><strong>{{ $average($summary['max_mhz'], 1000, ' GHz') }}</strong></td>
<td><small>Equipos con batería</small><strong>{{ $summary['battery_yes'] }} / {{ $summary['battery_known'] }}</strong></td>
</tr></table>
<h2>Relación de equipos</h2>
<table class="table"><thead><tr>
<th class="order">#</th><th class="name">Nombre</th><th class="id">ID del equipo</th><th class="os">Sistema operativo</th><th class="processor">Procesador</th><th class="cores">Núcleos / hilos</th><th class="ram">RAM</th><th class="storage">Almac.</th><th class="battery">Batería</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td>{{ $loop->iteration }}</td>
<td>
  <strong>{{ $row['name'] }}</strong>
  @if($row['is_demo'])
    <div class="small">Demostración</div>
  @endif
</td>
<td class="uid">{{ $row['uuid'] }}</td>
<td>{{ $row['platform'] }}</td>
<td>{{ $fmt($row['processor']) }}</td>
<td>{{ $fmt($row['physical_cores']) }} / {{ $fmt($row['logical_processors']) }}</td>
<td>{{ $gib($row['ram_bytes']) }}</td>
<td>
  {{ $gib($row['storage_bytes']) }}
  @if($row['storage_scope'] === 'primary_volume')
    <div class="small">Unidad principal</div>
  @elseif($row['storage_media'])
    <div class="small">{{ $row['storage_media'] }}</div>
  @endif
</td>
<td>{{ $battery($row['battery']) }}</td>
</tr>
@empty
<tr><td colspan="9">Todavía no hay equipos registrados en el inventario operativo.</td></tr>
@endforelse
</tbody></table>
<p class="note">Criterio técnico de ordenación, no una clasificación de rendimiento por pruebas comparativas. El almacenamiento suma los volúmenes declarados no extraíbles y no equivale necesariamente a capacidad física de discos. Cuando no hay desglose, se muestra solo la unidad principal. Las unidades de red identificadas y los medios extraíbles están excluidos. Si el equipo no distingue una unidad de red, su capacidad requiere verificación. Cada promedio considera únicamente los equipos con el dato disponible. RAM: {{ $summary['ram']['known'] }}; almacenamiento: {{ $summary['storage']['known'] }}; núcleos: {{ $summary['physical_cores']['known'] }}; frecuencia: {{ $summary['max_mhz']['known'] }}. La presencia de batería se conoce en {{ $summary['battery_known'] }} equipos. No se completan valores desconocidos con estimaciones.</p>
@foreach(array_chunk($rows, 4) as $chunk)
<div class="section">
<div class="section-heading"><h2>Ficha técnica de los equipos</h2><span class="small">{{ $inventory['count'] }} equipos en el inventario</span></div>
<table class="cards-table">
@foreach(array_chunk($chunk, 2) as $pair)
<tr>
@foreach($pair as $row)
<td>
<div class="device-card">
<strong class="device-title">{{ $row['name'] }}</strong>
<p class="device-sub">
  {{ $row['hostname'] }}
  @if($row['is_demo'])
    · Equipo de demostración
  @endif
</p>
<table class="facts">
<tr><td>Identificador</td><td class="uid">{{ $row['uuid'] }}</td></tr>
<tr><td>Sistema</td><td>{{ $row['platform'] }}</td></tr>
<tr><td>Procesador</td><td>{{ $fmt($row['processor']) }}</td></tr>
<tr><td>Núcleos / hilos</td><td>{{ $fmt($row['physical_cores']) }} / {{ $fmt($row['logical_processors']) }}</td></tr>
<tr><td>Frecuencia máxima</td><td>{{ $row['max_mhz'] ? number_format($row['max_mhz'] / 1000, 2, ',', '.').' GHz' : 'No informado' }}</td></tr>
<tr><td>Memoria RAM</td><td>{{ $gib($row['ram_bytes']) }}</td></tr>
<tr>
<td>{{ $row['storage_scope'] === 'primary_volume' ? 'Unidad principal' : 'Volúmenes declarados' }}</td>
<td>
  {{ $gib($row['storage_bytes']) }}
  @if($row['storage_media'])
    · {{ $row['storage_media'] }}
  @endif
</td>
</tr>
<tr><td>Fabricante / modelo</td><td>{{ $fmt($row['manufacturer']) }} · {{ $fmt($row['model']) }}</td></tr>
<tr><td>Batería</td><td>{{ $battery($row['battery']) }}</td></tr>
<tr><td>Última comunicación</td><td>{{ $presenter->shortDateTime($row['last_seen_at']) }}{{ $row['last_seen_at'] ? ' h' : '' }}</td></tr>
<tr><td>Último inventario</td><td>{{ $presenter->shortDateTime($row['inventory_captured_at']) }}{{ $row['inventory_captured_at'] ? ' h' : '' }}</td></tr>
<tr><td>Último diagnóstico</td><td>{{ $presenter->shortDateTime($row['diagnostic_captured_at']) }}{{ $row['diagnostic_captured_at'] ? ' h' : '' }}</td></tr>
</table>
</div>
</td>
@endforeach
@if(count($pair) === 1)
<td></td>
@endif
</tr>
@endforeach
</table>
</div>
@endforeach
</body></html>
