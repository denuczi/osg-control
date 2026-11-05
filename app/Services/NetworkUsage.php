<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Durable, revisioned NIC counter ledger; totals are network traffic, not ISP usage. */
final class NetworkUsage
{
    public const RETENTION_DAYS = 400;
    private const MAX_BYTES_PER_BUCKET = 100000000000000; // 100 TB per interface-hour hard sanity cap

    public function accept(Device $device, array $snapshots): void
    {
        DB::transaction(function () use ($device, $snapshots): void {
            foreach ($snapshots as $item) {
                $hour = CarbonImmutable::parse($item['hour_utc'])->utc();
                if ($hour->minute !== 0 || $hour->second !== 0 || $hour->micro !== 0 ||
                    $hour->lt(now()->subDays(self::RETENTION_DAYS)) || $hour->gt(now()->addHour()) ||
                    $item['rx_bytes'] > self::MAX_BYTES_PER_BUCKET || $item['tx_bytes'] > self::MAX_BYTES_PER_BUCKET) {
                    throw ValidationException::withMessages(['buckets' => 'Hora o volumen fuera de los límites permitidos.']);
                }
                $identity = ['device_id' => $device->id, 'bucket_uuid' => $item['bucket_uuid']];
                $old = DB::table('network_usage_buckets')->where($identity)->first();
                if ($old && ($old->interface_name !== $item['interface_name'] ||
                    $old->boot_id !== $item['boot_id'] ||
                    CarbonImmutable::parse($old->hour_utc)->utc()->ne($hour))) {
                    throw ValidationException::withMessages(['buckets' => 'Identidad de intervalo inconsistente.']);
                }
                if (!$old && DB::table('network_usage_buckets')
                    ->where('device_id', $device->id)->where('boot_id', $item['boot_id'])
                    ->where('interface_name', $item['interface_name'])
                    ->where('hour_utc', $hour->format('Y-m-d H:i:s'))->exists()) {
                    throw ValidationException::withMessages(['buckets' => 'Identidad duplicada para la misma interfaz y hora.']);
                }
                if ($old && $item['revision'] <= (int) $old->revision) {
                    // Older or duplicate delivery must not change already accepted totals.
                    if ($item['revision'] === (int) $old->revision &&
                        ($item['rx_bytes'] !== (int) $old->rx_bytes || $item['tx_bytes'] !== (int) $old->tx_bytes ||
                        $item['observed_seconds'] !== (int) $old->observed_seconds)) {
                        throw ValidationException::withMessages(['buckets' => 'Revisión repetida con contenido diferente.']);
                    }
                    continue;
                }
                $rx = $item['rx_bytes'] - ($old?->rx_bytes ?? 0);
                $tx = $item['tx_bytes'] - ($old?->tx_bytes ?? 0);
                $seconds = $item['observed_seconds'] - ($old?->observed_seconds ?? 0);
                if (min($rx, $tx, $seconds) < 0 || $item['observed_seconds'] > 3600) {
                    throw ValidationException::withMessages(['buckets' => 'Los contadores no pueden retroceder.']);
                }
                $fields = [
                    'interface_name' => $item['interface_name'], 'boot_id' => $item['boot_id'],
                    'hour_utc' => $hour->format('Y-m-d H:i:s'), 'revision' => $item['revision'],
                    'rx_bytes' => $item['rx_bytes'], 'tx_bytes' => $item['tx_bytes'],
                    'observed_seconds' => $item['observed_seconds'], 'updated_at' => now(),
                ];
                if ($old) {
                    DB::table('network_usage_buckets')->where($identity)->update($fields);
                } else {
                    DB::table('network_usage_buckets')->insert($identity + $fields);
                }
                $this->increment('network_usage_hourly', ['device_id' => $device->id, 'hour_utc' => $hour->format('Y-m-d H:i:s')], $rx, $tx, $seconds);
                $this->applyDaily($device->id, $hour, $rx, $tx, $seconds);
            }
        }, 3);
    }

    /** Allocate hourly totals across local midnight conservatively (approximation only at boundaries). */
    private function applyDaily(int $deviceId, CarbonImmutable $hour, int $rx, int $tx, int $seconds): void
    {
        $tz = (string) config('nodo.network_report_timezone', 'America/Argentina/Cordoba');
        $local = $hour->setTimezone($tz);
        $endLocal = $hour->addHour()->subMicrosecond()->setTimezone($tz);
        $firstDay = $local->format('Y-m-d');
        if ($firstDay === $endLocal->format('Y-m-d')) {
            $this->increment('network_usage_daily', ['device_id' => $deviceId, 'day' => $firstDay], $rx, $tx, $seconds);
            return;
        }
        $nextMidnight = $local->addDay()->startOfDay()->utc();
        $part = max(0, min(3600, $nextMidnight->timestamp - $hour->timestamp));
        $rx1 = intdiv($rx * $part, 3600);
        $tx1 = intdiv($tx * $part, 3600);
        $s1 = intdiv($seconds * $part, 3600);
        $this->increment('network_usage_daily', ['device_id' => $deviceId, 'day' => $firstDay], $rx1, $tx1, $s1);
        $this->increment('network_usage_daily', ['device_id' => $deviceId, 'day' => $endLocal->format('Y-m-d')], $rx-$rx1, $tx-$tx1, $seconds-$s1);
    }

    private function increment(string $table, array $identity, int $rx, int $tx, int $seconds): void
    {
        DB::table($table)->insertOrIgnore($identity + ['rx_bytes' => 0, 'tx_bytes' => 0, 'observed_seconds' => 0]);
        if ($rx === 0 && $tx === 0 && $seconds === 0) {
            return;
        }
        DB::table($table)->where($identity)->incrementEach([
            'rx_bytes' => $rx, 'tx_bytes' => $tx, 'observed_seconds' => $seconds,
        ]);
    }
}
