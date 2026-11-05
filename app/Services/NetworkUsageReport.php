<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bounded reports: daily aggregates only, no scanning high-frequency telemetry. */
final class NetworkUsageReport
{
    public function period(Request $request): array
    {
        $input = $request->validate([
            'period' => ['nullable', 'in:today,yesterday,3d,week,month,30d,custom'],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
        ]);
        $tz = app(DisplayTimezone::class)->current();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $period = $input['period'] ?? 'month';
        [$from, $to] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            '3d' => [$today->subDays(2), $today],
            'week' => [$today->startOfWeek(\Carbon\CarbonInterface::MONDAY), $today],
            '30d' => [$today->subDays(29), $today],
            'custom' => [CarbonImmutable::createFromFormat('!Y-m-d', $input['from'], $tz), CarbonImmutable::createFromFormat('!Y-m-d', $input['to'], $tz)],
            default => [$today->startOfMonth(), $today],
        };
        if ($from->gt($to) || $to->gt($today) || $from->lt($today->subDays(NetworkUsage::RETENTION_DAYS-1)) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['period' => 'Período inválido: máximo 366 días dentro de la retención.']);
        }
        return ['period' => $period, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'timezone' => $tz];
    }

    public function data(array $range, ?int $deviceId = null): array
    {
        $sourceZone = (string) config('nodo.network_report_timezone', 'America/Argentina/Cordoba');
        if ($range['timezone'] !== $sourceZone) {
            $projected = app(NetworkUsageLocalProjection::class)->data($range, $deviceId);
            if ($projected['hourly_rows_available']) {
                unset($projected['hourly_rows_available']);
                return $projected;
            }
            // Legacy daily-only history cannot be re-bucketed faithfully.
            // Display it with its real origin zone instead of assigning it
            // misleading labels in the newly selected zone.
            $legacy = $this->data([...$range, 'timezone' => $sourceZone], $deviceId);
            if ($legacy['days_with_data'] === 0) {
                unset($projected['hourly_rows_available']);
                return $projected;
            }
            $legacy['requested_timezone'] = $range['timezone'];
            $legacy['disclaimer'] .= ' No se dispone del detalle horario necesario para reconstruir estos días con la zona seleccionada; se muestran con su zona original.';
            return $legacy;
        }
        $query = DB::table('network_usage_daily as u')->join('devices as d', 'd.id', '=', 'u.device_id')
            ->whereBetween('u.day', [$range['from'], $range['to']])
            ->whereNull('d.revoked_at')->where('d.is_demo', false);
        if ($deviceId !== null) {
            $query->where('u.device_id', $deviceId);
        }
        $daily = (clone $query)->selectRaw('u.day, SUM(u.rx_bytes) as rx_bytes, SUM(u.tx_bytes) as tx_bytes, SUM(u.observed_seconds) as observed_seconds')
            ->groupBy('u.day')->orderBy('u.day')->get()->keyBy('day');
        $perDevice = (clone $query)->selectRaw('u.device_id, d.uuid, d.name, SUM(u.rx_bytes) as rx_bytes, SUM(u.tx_bytes) as tx_bytes')
            ->groupBy('u.device_id', 'd.uuid', 'd.name')->orderByRaw('(SUM(u.rx_bytes) + SUM(u.tx_bytes)) DESC')->limit(100)->get();
        $rx = (int) $daily->sum('rx_bytes');
        $tx = (int) $daily->sum('tx_bytes');
        $devicesMeasured = (clone $query)->distinct()->count('u.device_id');
        $registered = $deviceId !== null ? 1 : DB::table('devices')->whereNull('revoked_at')->where('is_demo', false)->count();
        $dayRows = [];
        $tz = $range['timezone'];
        for ($day = CarbonImmutable::createFromFormat('!Y-m-d', $range['from'], $tz),
             $end = CarbonImmutable::createFromFormat('!Y-m-d', $range['to'], $tz);
             $day->lte($end); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $found = $daily->get($key);
            $dayRows[] = ['day' => $key, 'rx_bytes' => $found ? (int)$found->rx_bytes : null,
                'tx_bytes' => $found ? (int)$found->tx_bytes : null,
                'observed_seconds' => $found ? (int)$found->observed_seconds : null];
        }
        return $range + [
            'scope' => $deviceId === null ? 'fleet' : 'device',
            'rx_bytes' => $daily->isEmpty() ? null : $rx,
            'tx_bytes' => $daily->isEmpty() ? null : $tx,
            'total_bytes' => $daily->isEmpty() ? null : $rx + $tx,
            'measured_devices' => $devicesMeasured, 'registered_devices' => $registered,
            'average_per_measured_device_bytes' => $devicesMeasured > 0 ? intdiv($rx + $tx, $devicesMeasured) : null,
            'days_with_data' => $daily->count(), 'days_in_period' => count($dayRows),
            'daily' => $dayRows,
            'devices' => $perDevice->map(fn ($r) => [
                'uuid' => $r->uuid, 'name' => $r->name,
                'rx_bytes' => (int) $r->rx_bytes, 'tx_bytes' => (int) $r->tx_bytes,
                'total_bytes' => (int) $r->rx_bytes + (int) $r->tx_bytes,
            ])->values()->all(),
            'measurement' => 'network_interface_traffic',
            'disclaimer' => 'Incluye tráfico LAN y de Internet de las interfaces seleccionadas. No equivale al consumo facturado por el proveedor. La atribución cerca del cambio de día es proporcional al tiempo; los días sin datos no significan consumo cero.',
        ];
    }
}
