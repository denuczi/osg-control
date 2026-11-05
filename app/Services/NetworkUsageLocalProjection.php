<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only re-bucketing of existing UTC hourly traffic into selected local days.
 * Does not alter the append-only hourly ledger or the original daily aggregates.
 */
final class NetworkUsageLocalProjection
{
    public function data(array $range, ?int $deviceId = null): array
    {
        $zone = $range['timezone'];
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $range['from'], $zone);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $range['to'], $zone);
        $startUtc = $first->startOfDay()->utc();
        $endUtc = $last->addDay()->startOfDay()->utc();

        $days = [];
        for ($date = $first; $date->lte($last); $date = $date->addDay()) {
            $days[$date->format('Y-m-d')] = ['rx_bytes' => null, 'tx_bytes' => null, 'observed_seconds' => null];
        }
        $devices = [];
        // Query begins one hour earlier because an hourly bucket can overlap a
        // local midnight (e.g. UTC+05:30); excluded fractions are ignored below.
        $query = DB::table('network_usage_hourly as h')
            ->join('devices as d', 'd.id', '=', 'h.device_id')
            ->where('h.hour_utc', '>=', $startUtc->subHour()->format('Y-m-d H:i:s'))
            ->where('h.hour_utc', '<', $endUtc->format('Y-m-d H:i:s'))
            ->whereNull('d.revoked_at')->where('d.is_demo', false)
            ->when($deviceId !== null, fn ($q) => $q->where('h.device_id', $deviceId))
            ->selectRaw('h.hour_utc, h.device_id, d.uuid, d.name, SUM(h.rx_bytes) as rx_bytes, SUM(h.tx_bytes) as tx_bytes, SUM(h.observed_seconds) as observed_seconds')
            ->groupBy('h.hour_utc', 'h.device_id', 'd.uuid', 'd.name')
            ->orderBy('h.hour_utc')->orderBy('h.device_id');

        foreach ($query->cursor() as $row) {
            $hour = CarbonImmutable::parse($row->hour_utc, 'UTC')->utc();
            $endHour = $hour->addHour();
            $firstDay = $hour->setTimezone($zone)->format('Y-m-d');
            $lastDay = $endHour->subMicrosecond()->setTimezone($zone)->format('Y-m-d');
            $rx = (int) $row->rx_bytes;
            $tx = (int) $row->tx_bytes;
            $secondsObserved = (int) $row->observed_seconds;
            if ($firstDay !== $lastDay) {
                $midnight = $hour->setTimezone($zone)->addDay()->startOfDay()->utc();
                $seconds = max(0, min(3600, $midnight->timestamp - $hour->timestamp));
                $rxFirst = intdiv($rx * $seconds, 3600);
                $txFirst = intdiv($tx * $seconds, 3600);
                $observedFirst = intdiv($secondsObserved * $seconds, 3600);
                $segments = [
                    [$firstDay, $rxFirst, $txFirst, $observedFirst],
                    [$lastDay, $rx - $rxFirst, $tx - $txFirst, $secondsObserved - $observedFirst],
                ];
            } else {
                $segments = [[$firstDay, $rx, $tx, $secondsObserved]];
            }
            foreach ($segments as [$day, $partRx, $partTx, $partObserved]) {
                if (! array_key_exists($day, $days)) continue;
                $days[$day]['rx_bytes'] = ($days[$day]['rx_bytes'] ?? 0) + $partRx;
                $days[$day]['tx_bytes'] = ($days[$day]['tx_bytes'] ?? 0) + $partTx;
                $days[$day]['observed_seconds'] = ($days[$day]['observed_seconds'] ?? 0) + $partObserved;
                $id = (int) $row->device_id;
                if (! isset($devices[$id])) {
                    $devices[$id] = ['uuid' => $row->uuid, 'name' => $row->name,
                        'rx_bytes' => 0, 'tx_bytes' => 0, 'total_bytes' => 0];
                }
                $devices[$id]['rx_bytes'] += $partRx;
                $devices[$id]['tx_bytes'] += $partTx;
                $devices[$id]['total_bytes'] += $partRx + $partTx;
            }
        }

        $deviceCount = count($devices);
        $rxTotal = array_sum(array_column($devices, 'rx_bytes'));
        $txTotal = array_sum(array_column($devices, 'tx_bytes'));
        $daily = [];
        $measuredDays = 0;
        foreach ($days as $day => $values) {
            if ($values['rx_bytes'] !== null) $measuredDays++;
            $daily[] = ['day' => $day] + $values;
        }
        $perDevice = array_values($devices);
        usort($perDevice, fn ($a, $b) => $b['total_bytes'] <=> $a['total_bytes']);
        $registered = $deviceId !== null ? 1 : DB::table('devices')
            ->whereNull('revoked_at')->where('is_demo', false)->count();

        return $range + [
            'scope' => $deviceId === null ? 'fleet' : 'device',
            'rx_bytes' => $measuredDays ? $rxTotal : null,
            'tx_bytes' => $measuredDays ? $txTotal : null,
            'total_bytes' => $measuredDays ? $rxTotal + $txTotal : null,
            'measured_devices' => $deviceCount, 'registered_devices' => $registered,
            'average_per_measured_device_bytes' => $deviceCount ? intdiv($rxTotal + $txTotal, $deviceCount) : null,
            'days_with_data' => $measuredDays, 'days_in_period' => count($daily),
            'daily' => $daily, 'devices' => array_slice($perDevice, 0, 100),
            'measurement' => 'network_interface_traffic',
            'disclaimer' => 'Incluye tráfico LAN y de Internet de las interfaces seleccionadas. Los intervalos horarios se atribuyen proporcionalmente si cruzan la medianoche local. Los días sin datos no equivalen a consumo cero.',
            'hourly_rows_available' => $measuredDays > 0,
        ];
    }
}
