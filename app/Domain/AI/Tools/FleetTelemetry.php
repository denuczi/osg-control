<?php

namespace App\Domain\AI\Tools;

use App\Services\Reports\ReportShutdownAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Models\Device;

/** Bounded, read-only projections over measurements already collected by OSG Nodo Control. */
final class FleetTelemetry
{
    private const MAX_SHUTDOWN_SIGNALS = 2000;
    private const MAX_RESULT_ROWS = 30;

    public function __construct(private readonly ReportShutdownAssessment $shutdownAssessment) {}

    public function network(string $period = '7d'): array
    {
        [$from, $to, $label] = $this->dates($period);
        $base = DB::table('network_usage_daily as u')
            ->join('devices as d', 'd.id', '=', 'u.device_id')
            ->whereBetween('u.day', [$from, $to])
            ->whereNull('d.revoked_at')->whereNull('d.archived_at')
            ->where('d.is_demo', false);

        $totals = (clone $base)->selectRaw('SUM(u.rx_bytes) AS rx, SUM(u.tx_bytes) AS tx, COUNT(DISTINCT u.device_id) AS devices_measured, COUNT(DISTINCT u.day) AS days_measured')->first();
        $measured = (int) ($totals->devices_measured ?? 0);
        $rows = (clone $base)
            ->selectRaw('u.device_id, d.uuid, d.name, SUM(u.rx_bytes) AS rx, SUM(u.tx_bytes) AS tx')
            ->groupBy('u.device_id', 'd.uuid', 'd.name')
            ->orderByRaw('(SUM(u.rx_bytes) + SUM(u.tx_bytes)) DESC')
            ->orderBy('d.uuid')
            ->limit(self::MAX_RESULT_ROWS)->get();
        $rx = $measured > 0 ? (int) $totals->rx : null;
        $tx = $measured > 0 ? (int) $totals->tx : null;
        $registered = DB::table('devices')->whereNull('revoked_at')->whereNull('archived_at')
            ->where('is_demo', false)->count();

        return [
            'period' => $period, 'period_label' => $label,
            'from' => $from, 'to' => $to,
            'aggregation_timezone' => (string) config('nodo.network_report_timezone', 'America/Argentina/Cordoba'),
            'rx_bytes' => $rx, 'tx_bytes' => $tx,
            'total_bytes' => $rx !== null && $tx !== null ? $rx + $tx : null,
            'registered_devices' => $registered, 'measured_devices' => $measured,
            'days_with_data' => (int) ($totals->days_measured ?? 0),
            'returned' => $rows->count(), 'truncated' => $measured > $rows->count(),
            'devices' => $rows->map(static fn ($r): array => [
                'uuid' => $r->uuid, 'name' => $r->name,
                'rx_bytes' => (int) $r->rx, 'tx_bytes' => (int) $r->tx,
                'total_bytes' => (int) $r->rx + (int) $r->tx,
            ])->all(),
            'notes' => 'Tráfico registrado por las interfaces de red, que puede incluir Internet y red local. No equivale al consumo facturado por un proveedor. Los días y equipos sin capturas no representan consumo cero. La cobertura de medición puede ser incompleta.',
        ];
    }

    /**
     * Solo eventos connected con media_class=usb: una detección al iniciar
     * el agente, una expulsión o una unidad óptica no demuestran que alguien
     * acaba de conectar un pendrive. Los equipos desconectados conservan historial.
     */
    public function usbConnections(string $period = '7d', ?string $deviceUuid = null): array
    {
        $timezone = app(\App\Services\DisplayTimezone::class)->current();
        $now = CarbonImmutable::now($timezone);
        [$from, $to, $label] = match ($period) {
            'today' => [$now->startOfDay(), $now, 'hoy'],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay(), 'ayer'],
            'month' => [$now->startOfMonth(), $now, 'este mes'],
            '30d' => [$now->subDays(29)->startOfDay(), $now, 'los últimos 30 días'],
            default => [$now->subDays(6)->startOfDay(), $now, 'los últimos 7 días'],
        };

        $target = null;
        if ($deviceUuid !== null) {
            $target = Device::query()->where('uuid', $deviceUuid)
                ->whereNull('revoked_at')->whereNull('archived_at')
                ->first(['id', 'uuid', 'name', 'hostname', 'is_demo']);
            if ($target === null) return ['error' => 'equipo_no_encontrado'];
            if ($target->is_demo) return ['error' => 'equipo_demostracion'];
        }

        $base = DB::table('device_media_events as e')
            ->join('devices as d', 'd.id', '=', 'e.device_id')
            ->whereNull('d.revoked_at')->whereNull('d.archived_at')
            ->where('d.is_demo', false)
            ->where('e.kind', 'connected')
            ->when($target !== null, static fn ($query) => $query->where('e.device_id', $target->id))
            ->where('e.volume->media_class', 'usb')
            ->whereBetween('e.observed_at', [
                $from->utc()->format('Y-m-d H:i:s'),
                $to->utc()->format('Y-m-d H:i:s'),
            ]);

        $totals = (clone $base)->selectRaw('COUNT(*) AS total, COUNT(DISTINCT e.device_id) AS devices')->first();
        $events = (clone $base)->orderByDesc('e.observed_at')->orderByDesc('e.id')
            ->limit(self::MAX_RESULT_ROWS)
            ->get(['d.uuid', 'd.name', 'e.event_uuid', 'e.observed_at', 'e.volume']);

        return [
            'period' => $period, 'period_label' => $label,
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'target_device' => $target === null ? null : [
                'uuid' => $target->uuid, 'name' => $target->name, 'hostname' => $target->hostname,
            ],
            'connection_events' => (int) ($totals->total ?? 0),
            'devices_with_connections' => (int) ($totals->devices ?? 0),
            'returned' => $events->count(),
            'truncated' => (int) ($totals->total ?? 0) > $events->count(),
            'usb_events' => $events->map(static function ($event): array {
                $volume = json_decode((string) $event->volume, true);
                if (! is_array($volume)) $volume = [];
                return [
                    'event_uuid' => (string) $event->event_uuid,
                    'device_uuid' => (string) $event->uuid,
                    'device_name' => (string) $event->name,
                    'label' => trim((string) ($volume['label'] ?? '')) ?: null,
                    'mountpoint' => trim((string) ($volume['mountpoint'] ?? '')) ?: null,
                    'observed_at' => CarbonImmutable::parse($event->observed_at, 'UTC')->toIso8601String(),
                ];
            })->all(),
            'notes' => 'Solo conexiones de unidades clasificadas como almacenamiento USB y recibidas del agente. '
                .'No incluye detecciones al iniciar el equipo, desconexiones ni discos ópticos. '
                .'La clasificación USB procede de las notificaciones del agente y no acredita por sí sola el bus físico del dispositivo. No identifica a la persona que lo conectó. '
                .'Si no hay eventos, eso no demuestra que no se hayan conectado dispositivos: la cobertura o retención puede ser incompleta.',
        ];
    }

    public function shutdowns(string $period = '7d'): array
    {
        // UTC persisted events; select local calendar-day boundaries in the chosen display zone.
        $tz = app(\App\Services\DisplayTimezone::class)->current();
        $now = CarbonImmutable::now($tz);
        $from = match ($period) {
            'today' => $now->startOfDay(),
            'yesterday' => $now->subDay()->startOfDay(),
            '30d' => $now->subDays(29)->startOfDay(),
            'month' => $now->startOfMonth(),
            default => $now->subDays(6)->startOfDay(),
        };
        $to = $period === 'yesterday' ? $now->subDay()->endOfDay() : $now;
        $events = DB::table('device_system_events as e')
            ->join('devices as d', 'd.id', '=', 'e.device_id')
            ->whereNull('d.revoked_at')->whereNull('d.archived_at')
            ->where('d.is_demo', false)
            ->whereBetween('e.observed_at', [$from->utc()->format('Y-m-d H:i:s'), $to->utc()->format('Y-m-d H:i:s')])
            ->whereIn('e.event_id', [41, 6008])
            ->orderByDesc('e.observed_at')->orderByDesc('e.id')
            ->limit(self::MAX_SHUTDOWN_SIGNALS + 1)
            ->get(['e.device_id', 'e.event_id', 'e.provider', 'e.channel', 'e.observed_at', 'd.uuid', 'd.name']);
        $truncated = $events->count() > self::MAX_SHUTDOWN_SIGNALS;
        $byDevice = [];
        foreach ($events->take(self::MAX_SHUTDOWN_SIGNALS) as $event) {
            $key = (int) $event->device_id;
            $byDevice[$key]['uuid'] = $event->uuid;
            $byDevice[$key]['name'] = $event->name;
            $byDevice[$key]['events'][] = [
                'event_id' => (int) $event->event_id,
                'provider' => (string) $event->provider,
                'channel' => (string) $event->channel,
                'observed_at' => (string) $event->observed_at,
            ];
        }
        $rows = [];
        $totalIncidents = 0;
        $signals = 0;
        foreach ($byDevice as $row) {
            $summary = $this->shutdownAssessment->summarize($row['events']);
            if ($summary['unclean_incidents'] === 0) continue;
            $totalIncidents += $summary['unclean_incidents'];
            $signals += $summary['unclean_event_records'];
            $rows[] = [
                'uuid' => $row['uuid'], 'name' => $row['name'],
                'incidents' => $summary['unclean_incidents'],
                'event_records' => $summary['unclean_event_records'],
                'latest_signal_at' => CarbonImmutable::parse(max(array_column($row['events'], 'observed_at')), 'UTC')->toIso8601String(),
            ];
        }
        usort($rows, static fn (array $a, array $b): int =>
            ($b['incidents'] <=> $a['incidents']) ?: strcmp($a['uuid'], $b['uuid']));
        return [
            'period' => $period, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'registered_devices' => DB::table('devices')->whereNull('revoked_at')->whereNull('archived_at')->where('is_demo', false)->count(),
            'devices_with_signals' => count($rows), 'unclean_incidents' => $totalIncidents,
            'unclean_event_records' => $signals,
            'truncated' => $truncated, 'returned' => min(self::MAX_RESULT_ROWS, count($rows)),
            'devices' => array_slice($rows, 0, self::MAX_RESULT_ROWS),
            'notes' => 'Se revisaron únicamente los eventos de Windows recibidos y conservados para este período. 41 y 6008 cercanos pueden corresponder a un mismo incidente; no demuestran su causa. Un equipo desconectado no significa que se haya apagado inesperadamente. La ausencia de eventos no demuestra que todos los apagados hayan sido normales.',
        ];
    }

    /** @return array{string,string,string} */
    private function dates(string $period): array
    {
        $tz = (string) config('nodo.network_report_timezone', 'America/Argentina/Cordoba');
        $today = CarbonImmutable::now($tz)->startOfDay();
        [$from, $to, $label] = match ($period) {
            'today' => [$today, $today, 'hoy'],
            'yesterday' => [$today->subDay(), $today->subDay(), 'ayer'],
            'month' => [$today->startOfMonth(), $today, 'el mes actual'],
            '30d' => [$today->subDays(29), $today, 'los últimos 30 días'],
            default => [$today->subDays(6), $today, 'los últimos 7 días'],
        };
        return [$from->format('Y-m-d'), $to->format('Y-m-d'), $label];
    }
}
