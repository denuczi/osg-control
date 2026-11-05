<?php

namespace App\Services\Reports;

use App\Models\Device;
use App\Services\MonitorSettings;
use App\Services\NetworkUsageReport;
use App\Services\DisplayTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Canonical source dataset shared by the PDF and AI; never derived from rendered views. */
final class ReportDataCollector
{
    public function collect(Device $device, CarbonImmutable $until): array
    {
        $from = $until->subDays(7);
        $device->load(['inventory', 'diagnostic']);
        $inventory = $device->inventory;
        $diag = $device->diagnostic;
        $battery = data_get($diag?->hardware, 'data');
        $capacityDesign = is_numeric(data_get($battery, 'BatteryDesignCapacity')) ? (float) data_get($battery, 'BatteryDesignCapacity') : null;
        $capacityFull = is_numeric(data_get($battery, 'BatteryFullChargeCapacity')) ? (float) data_get($battery, 'BatteryFullChargeCapacity') : null;
        $batteryHealth = $capacityDesign !== null && $capacityDesign > 0 && $capacityFull !== null && $capacityFull >= 0
            ? round(min(100, 100 * $capacityFull / $capacityDesign), 1) : null;
        $metrics = $this->metrics($device->id, $from, $until);
        $events = $this->events($device->id, $from, $until);
        $media = $this->media($device->id, $from, $until);
        $displayZone = app(DisplayTimezone::class)->current();
        $networkZone = (string) config('nodo.network_report_timezone', DisplayTimezone::DEFAULT_ZONE);
        if ($displayZone === $networkZone) {
            $network = DB::table('network_usage_daily')->where('device_id', $device->id)
                ->whereBetween('day', [$from->format('Y-m-d'), $until->format('Y-m-d')])
                ->orderBy('day')->get(['day', 'rx_bytes', 'tx_bytes', 'observed_seconds'])
                ->map(fn ($row) => (array) $row)->all();
        } else {
            // The PDF uses the same read-only local-day projection as the panel.
            // The original hourly and daily counters remain untouched.
            $usage = app(NetworkUsageReport::class)->data([
                'period' => 'custom',
                'from' => $from->setTimezone($displayZone)->format('Y-m-d'),
                'to' => $until->setTimezone($displayZone)->format('Y-m-d'),
                'timezone' => $displayZone,
            ], $device->id);
            $networkZone = $usage['timezone'];
            $network = array_values(array_filter($usage['daily'],
                fn (array $day) => $day['rx_bytes'] !== null));
        }
        $commands = DB::table('device_commands')->where('device_id', $device->id)
            ->where('created_at', '>=', $from)->where('created_at', '<=', $until)
            ->selectRaw('action, status, COUNT(*) AS total')->groupBy('action', 'status')
            ->orderBy('action')->get()->map(fn ($row) => (array) $row)->all();

        return [
            'meta' => [
                'captured_at' => $until->toIso8601String(),
                'period_start' => $from->toIso8601String(),
                'period_end' => $until->toIso8601String(),
                'timezone' => (string) config('app.timezone', 'UTC'),
                'display_timezone' => $displayZone,
                'network_aggregation_timezone' => $networkZone,
                'interval_seconds' => app(MonitorSettings::class)->all()['metrics_interval_seconds'],
                'data_source' => 'OSG Nodo Control / mediciones persistidas',
            ],
            'device' => [
                'name' => $device->name, 'hostname' => $device->hostname,
                'uuid' => $device->uuid, 'platform' => $device->platform,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_sampled_at' => $device->last_sampled_at?->toIso8601String(),
                'cpu_percent' => $device->cpu_percent, 'memory_percent' => $device->memory_percent,
                'disk_percent' => $device->disk_percent,
                'memory_total_bytes' => $device->memory_total_bytes,
                'memory_used_bytes' => $device->memory_used_bytes,
                'memory_available_bytes' => $device->memory_available_bytes,
                'disk_total_bytes' => $device->disk_total_bytes,
                'disk_used_bytes' => $device->disk_used_bytes,
                'disk_free_bytes' => $device->disk_free_bytes,
                'network_rx_bps' => $device->network_rx_bps,
                'network_tx_bps' => $device->network_tx_bps,
                'processes_sampled_at' => $device->processes_sampled_at?->toIso8601String(),
                'processes' => collect($device->processes ?? [])->sortByDesc('memory_mb')
                    ->take(40)->values()->map(fn ($p) => [
                        'name' => $p['name'] ?? null, 'memory_mb' => $p['memory_mb'] ?? null,
                        'can_terminate' => $p['can_terminate'] ?? null,
                    ])->all(),
            ],
            'inventory' => [
                'captured_at' => $inventory?->captured_at?->toIso8601String(),
                'processor' => $inventory?->processor,
                'volumes' => $inventory?->volumes,
                'network' => $inventory?->network,
            ],
            'derived_indicators' => [
                'battery_charge_percent' => data_get($battery, 'BatteryPercent'),
                'battery_health_percent' => $batteryHealth,
                'battery_health_basis' => 'Capacidad de carga completa / capacidad de diseño; no equivale a carga actual',
                'wifi_latency_ms' => null,
                'malware_scan_verified' => null,
                'event_log_coverage_percent' => null,
            ],
            'windows_support' => app(WindowsSupportAssessment::class)->evaluate(
                data_get($diag?->security, 'data.Windows'), $until
            ),
            'diagnostic' => [
                'captured_at' => $diag?->captured_at?->toIso8601String(),
                'hardware' => $diag?->hardware,
                'disks' => $diag?->disks,
                'security' => $diag?->security,
            ],
            'metrics' => $metrics,
            'network_usage_daily' => $network,
            'system_events' => $events,
            'shutdown_activity' => app(ReportShutdownAssessment::class)->summarize(
                $events['recent'], $events['recent_truncated']
            ),
            'media_events' => $media,
            'command_outcomes' => $commands,
        ];
    }

    private function metrics(int $deviceId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $hours = [];
        $days = [];
        $daysLocal = [];
        $bins = ['cpu' => array_fill(0, 101, 0), 'ram' => array_fill(0, 101, 0)];
        $count = 0;
        $last = null;
        $gapCount = 0;
        $gapSeconds = 0;
        $interval = app(MonitorSettings::class)->all()['metrics_interval_seconds'];
        $first = null;
        $displayTimezone = app(\App\Services\DisplayTimezone::class)->current();
        $query = DB::table('metric_samples')->where('device_id', $deviceId)
            ->whereBetween('recorded_at', [$from, $until])->orderBy('recorded_at')->orderBy('id')
            ->select(['recorded_at', 'cpu_percent', 'memory_percent', 'disk_percent', 'network_rx_bps', 'network_tx_bps']);
        foreach ($query->cursor() as $sample) {
            $stamp = CarbonImmutable::parse($sample->recorded_at, 'UTC');
            $epoch = $stamp->getTimestamp();
            $first ??= $stamp->toIso8601String();
            if ($last !== null && $epoch - $last > $interval * 3) {
                $gapCount++;
                $gapSeconds += $epoch - $last;
            }
            $last = $epoch;
            $count++;
            $hour = $stamp->format('Y-m-d H:00');
            $day = $stamp->format('Y-m-d');
            $this->addMetricBucket($hours, $hour, $sample);
            $this->addMetricBucket($days, $day, $sample);
            $localDay = $stamp->setTimezone($displayTimezone)->format('Y-m-d');
            $this->addMetricBucket($daysLocal, $localDay, $sample);
            foreach (['cpu' => 'cpu_percent', 'ram' => 'memory_percent'] as $k => $column) {
                $index = (int) min(100, max(0, floor((float) ($sample->{$column} ?? 0))));
                $bins[$k][$index]++;
            }
        }
        $project = static function (array $data): array {
            return array_values(array_map(static fn ($b) => [
                'time' => $b['time'], 'samples' => $b['samples'],
                'cpu_avg' => round($b['cpu_sum'] / $b['samples'], 2),
                'ram_avg' => round($b['ram_sum'] / $b['samples'], 2),
                'disk_avg' => round($b['disk_sum'] / $b['samples'], 2),
                'cpu_max' => round($b['cpu_max'], 2), 'ram_max' => round($b['ram_max'], 2),
                'rx_bps_avg' => $b['rx_n'] > 0 ? round($b['rx_sum'] / $b['rx_n']) : null,
                'tx_bps_avg' => $b['tx_n'] > 0 ? round($b['tx_sum'] / $b['tx_n']) : null,
            ], $data));
        };
        $quantile = static function (array $bins, int $n): ?int {
            if ($n === 0) return null;
            $threshold = (int) ceil($n * .95);
            $acc = 0;
            foreach ($bins as $value => $frequency) {
                $acc += $frequency;
                if ($acc >= $threshold) return $value;
            }
            return null;
        };
        return [
            'samples' => $count, 'first_at' => $first,
            'last_at' => $last === null ? null : CarbonImmutable::createFromTimestamp($last, 'UTC')->toIso8601String(),
            'gaps_over_3_intervals' => $gapCount, 'gap_seconds_between_observations' => $gapSeconds,
            'expected_samples_estimate' => (int) ceil(7 * 86400 / $interval),
            'coverage_estimate_percent' => min(100, round(100 * $count / max(1, ceil(7 * 86400 / $interval)), 1)),
            'cpu_p95_approx' => $quantile($bins['cpu'], $count),
            'ram_p95_approx' => $quantile($bins['ram'], $count),
            'hourly' => $project($hours), 'daily' => $project($days),
            'daily_local' => $project($daysLocal),
        ];
    }

    private function addMetricBucket(array &$buckets, string $key, object $sample): void
    {
        if (! isset($buckets[$key])) {
            $buckets[$key] = ['time' => $key, 'samples' => 0,
                'cpu_sum' => 0.0, 'ram_sum' => 0.0, 'disk_sum' => 0.0,
                'cpu_max' => 0.0, 'ram_max' => 0.0,
                'rx_sum' => 0.0, 'tx_sum' => 0.0, 'rx_n' => 0, 'tx_n' => 0];
        }
        $bucket = &$buckets[$key];
        $bucket['samples']++;
        foreach (['cpu' => 'cpu_percent', 'ram' => 'memory_percent', 'disk' => 'disk_percent'] as $metric => $column) {
            $value = (float) ($sample->{$column} ?? 0);
            $bucket[$metric.'_sum'] += $value;
            if ($metric !== 'disk') {
                $bucket[$metric.'_max'] = max($bucket[$metric.'_max'], $value);
            }
        }
        foreach (['rx' => 'network_rx_bps', 'tx' => 'network_tx_bps'] as $metric => $column) {
            if ($sample->{$column} !== null) {
                $bucket[$metric.'_sum'] += (float) $sample->{$column};
                $bucket[$metric.'_n']++;
            }
        }
    }

    private function events(int $deviceId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $query = DB::table('device_system_events')->where('device_id', $deviceId)
            ->whereBetween('observed_at', [$from, $until]);
        $count = (clone $query)->count();
        $distribution = (clone $query)->selectRaw('channel, provider, event_id, level, COUNT(*) AS total')
            ->groupBy('channel', 'provider', 'event_id', 'level')->orderByDesc('total')->limit(401)
            ->get()->map(fn ($event) => (array) $event)->all();
        $recent = (clone $query)->orderByDesc('observed_at')->orderByDesc('id')->limit(200)
            ->get(['channel','provider','event_id','level','observed_at','record_id','message','user_sid'])
            ->map(fn ($event) => (array) $event)->all();
        return [
            'coverage_percent' => null,
            'scope' => 'Solo eventos del catálogo supervisado en System y Application; no mide cobertura de todos los eventos de Windows',
            'total' => $count, 'distribution' => array_slice($distribution, 0, 400), 'distribution_truncated' => count($distribution) > 400,
            'recent' => $recent, 'recent_truncated' => $count > count($recent)];
    }

    private function media(int $deviceId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $query = DB::table('device_media_events')->where('device_id', $deviceId)
            ->whereBetween('observed_at', [$from, $until]);
        $count = (clone $query)->count();
        $kinds = (clone $query)->selectRaw('kind, COUNT(*) AS total')->groupBy('kind')
            ->pluck('total', 'kind')->all();
        return ['total' => $count, 'by_kind' => $kinds,
            'recent' => (clone $query)->orderByDesc('observed_at')->limit(50)
                ->get(['kind','observed_at','volume'])->map(fn ($row) => [
                    'kind' => $row->kind, 'observed_at' => $row->observed_at,
                    'volume' => json_decode($row->volume ?? 'null', true),
                ])->all(), 'recent_truncated' => $count > 50];
    }
}
