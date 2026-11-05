<?php

namespace App\Services\Reports;

use App\Models\Device;
use App\Services\DisplayTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Independent evidence queries for AI; not derived from the report view or PDF. */
final class ReportAiEvidenceCollector
{
    public function collect(Device $device, CarbonImmutable $until): array
    {
        $from = $until->subDays(30);
        $fromNinety = $until->subDays(90);
        $events = DB::table('device_system_events')->where('device_id', $device->id)
            ->whereBetween('observed_at', [$fromNinety, $until]);
        $count = (clone $events)->count();
        $groups = (clone $events)->selectRaw('channel, provider, event_id, level, COUNT(*) AS total, MAX(observed_at) AS most_recent')
            ->groupBy('channel', 'provider', 'event_id', 'level')->orderByDesc('total')->limit(251)
            ->get()->map(fn ($row) => (array) $row)->all();
        $daily = (clone $events)->selectRaw('DATE(observed_at) AS day, COUNT(*) AS total')
            ->groupByRaw('DATE(observed_at)')->orderBy('day')->get()
            ->map(fn ($row) => (array) $row)->all();
        $mediaQuery = DB::table('device_media_events')->where('device_id', $device->id)
            ->whereBetween('observed_at', [$from, $until]);
        $network = DB::table('network_usage_daily')->where('device_id', $device->id)
            ->whereBetween('day', [$from->format('Y-m-d'), $until->format('Y-m-d')])
            ->selectRaw('COUNT(*) AS measured_days, SUM(rx_bytes) AS received_bytes, SUM(tx_bytes) AS sent_bytes, SUM(observed_seconds) AS observed_seconds')->first();
        $commandCounts = DB::table('device_commands')->where('device_id', $device->id)
            ->whereBetween('created_at', [$from, $until])
            ->selectRaw('action, status, COUNT(*) AS total')->groupBy('action', 'status')
            ->get()->map(fn ($row) => (array) $row)->all();
        return [
            'source' => 'consultas directas a SQLite, independientes de las tablas presentadas en el PDF',
            'window_90_days' => [
                'from' => $fromNinety->toIso8601String(), 'to' => $until->toIso8601String(),
                'event_count' => $count, 'event_types' => array_slice($groups, 0, 250),
                'event_types_truncated' => count($groups) > 250,
                'event_counts_by_day' => $daily, 'event_day_timezone' => 'UTC',
            ],
            'window_30_days' => [
                'media_count' => (clone $mediaQuery)->count(),
                'media_counts' => (clone $mediaQuery)->selectRaw('kind, COUNT(*) AS total')
                    ->groupBy('kind')->get()->map(fn ($row) => (array) $row)->all(),
                'network_usage' => $network ? (array) $network + ['day_aggregation_timezone' => (string) config('nodo.network_report_timezone', DisplayTimezone::DEFAULT_ZONE)] : null,
                'command_outcomes' => $commandCounts,
            ],
            'coverage' => [
                'windows_event_scope' => 'Solo categorías de eventos capturadas por el agente, no una auditoría exhaustiva',
                'windows_support' => 'La edición/build/ESU se evalúan de forma independiente en windows_support; ausencia de licencia comercial no descarta ESU de consumo',
                'historical_retention' => 'Los límites de retención pueden excluir eventos anteriores',
            ],
        ];
    }
}
