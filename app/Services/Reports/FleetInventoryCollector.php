<?php

namespace App\Services\Reports;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Crea una proyección limitada y consistente del inventario, sin leer la tabla paginada de Vue. */
final class FleetInventoryCollector
{
    public function collect(CarbonImmutable $generatedAt): array
    {
        $maxDevices = max(1, min(1000, (int) config('reports.fleet_max_devices', 250)));
        $rows = [];

        // Una misma instantánea de lectura incluye los dispositivos y sus relaciones,
        // incluso si llegan nuevas capturas mientras se recorren los lotes (SQLite WAL).
        DB::transaction(function () use ($maxDevices, &$rows): void {
            $query = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
            if ((clone $query)->count() > $maxDevices) {
                throw new RuntimeException('fleet_device_limit_exceeded');
            }

            $query->select([
                'id', 'uuid', 'name', 'hostname', 'platform', 'last_seen_at',
                'memory_total_bytes', 'disk_total_bytes', 'is_demo',
            ])->with([
                'inventory:id,device_id,processor,volumes,captured_at',
                'diagnostic:id,device_id,hardware,security,captured_at',
            ])->chunkById(40, function ($devices) use (&$rows): void {
                foreach ($devices as $device) {
                    $rows[] = $this->project($device);
                }
            });
        }, 2);

        // Orden reproducible: características técnicas declaradas; no equivale a benchmark.
        usort($rows, static function (array $a, array $b): int {
            foreach (['physical_cores', 'ram_bytes', 'logical_processors', 'max_mhz'] as $field) {
                $diff = ($b[$field] ?? -1) <=> ($a[$field] ?? -1);
                if ($diff !== 0) return $diff;
            }
            return strcmp($a['uuid'], $b['uuid']);
        });

        return [
            'generated_at' => $generatedAt->toIso8601String(),
            'display_timezone' => app(\App\Services\DisplayTimezone::class)->current(),
            'count' => count($rows),
            'summary' => [
                'ram' => $this->average($rows, 'ram_bytes'),
                // No mezclar la unidad principal de un equipo con el total de
                // volúmenes declarados de otro equipo.
                'storage' => $this->average($rows, 'storage_bytes', 'declared_volumes'),
                'physical_cores' => $this->average($rows, 'physical_cores'),
                'max_mhz' => $this->average($rows, 'max_mhz'),
                'battery_yes' => count(array_filter($rows, static fn ($r) => $r['battery'] === true)),
                'battery_known' => count(array_filter($rows, static fn ($r) => $r['battery'] !== null)),
            ],
            'rows' => $rows,
        ];
    }

    private function project(Device $device): array
    {
        $processor = $device->inventory?->processor ?? [];
        $section = $device->diagnostic?->hardware ?? [];
        $hardware = ($section['status'] ?? null) === 'ok' ? ($section['data'] ?? []) : [];
        $windows = data_get($device->diagnostic?->security, 'data.Windows');

        $modules = data_get($hardware, 'MemoryModules');
        $moduleBytes = 0;
        $complete = is_array($modules) && $modules !== [];
        if ($complete) {
            foreach ($modules as $module) {
                $size = $this->integer(data_get($module, 'CapacityBytes'), 16 * 1024 ** 4);
                if ($size === null || $moduleBytes > 16 * 1024 ** 4 - $size) {
                    $complete = false;
                    break;
                }
                $moduleBytes += $size;
            }
        }
        $ram = $complete ? $moduleBytes : $this->integer($device->memory_total_bytes, 16 * 1024 ** 4);

        [$storage, $media] = $this->volumeCapacity($device->inventory?->volumes ?? []);
        $scope = $storage !== null ? 'declared_volumes' : null;
        if ($storage === null) {
            $storage = $this->integer($device->disk_total_bytes, 1024 ** 5);
            $scope = $storage !== null ? 'primary_volume' : null;
        }

        $caption = trim((string) (data_get($windows, 'Caption') ?? ''));
        $version = trim((string) (data_get($windows, 'DisplayVersion') ?? ''));
        $platform = $caption !== '' ? $caption : $device->platform;
        if ($caption !== '' && $version !== '' && ! str_contains($caption, $version)) {
            $platform .= ' · '.$version;
        }

        $battery = data_get($hardware, 'BatteryPresent');
        return [
            'uuid' => $device->uuid,
            'name' => $device->name,
            'hostname' => $device->hostname,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'platform' => $platform ?: 'No informado',
            'manufacturer' => data_get($hardware, 'Manufacturer'),
            'model' => data_get($hardware, 'Model'),
            'processor' => data_get($processor, 'name'),
            'physical_cores' => $this->integer(data_get($processor, 'physical_cores'), 1024),
            'logical_processors' => $this->integer(data_get($processor, 'logical_processors'), 2048),
            'max_mhz' => $this->frequency(data_get($processor, 'max_mhz')),
            'ram_bytes' => $ram,
            'storage_bytes' => $storage,
            'storage_scope' => $scope,
            'storage_media' => $scope === 'declared_volumes' ? $media : null,
            'battery' => is_bool($battery) ? $battery : null,
            'is_demo' => (bool) $device->is_demo,
            'inventory_captured_at' => $device->inventory?->captured_at?->toIso8601String(),
            'diagnostic_captured_at' => $device->diagnostic?->captured_at?->toIso8601String(),
        ];
    }

    /** Capacidad lógica de volúmenes declarados no extraíbles, nunca "capacidad física total". */
    private function volumeCapacity(array $volumes): array
    {
        $sum = 0;
        $found = false;
        $seen = [];
        $mediaTypes = [];

        foreach ($volumes as $volume) {
            if (! is_array($volume) || filter_var($volume['removable'] ?? false, FILTER_VALIDATE_BOOLEAN)) continue;
            $category = strtolower(trim((string) ($volume['media_class'] ?? '')));
            $bus = strtolower(trim((string) ($volume['bus_type'] ?? '')));
            if (in_array($category, ['usb', 'optical', 'removable', 'network'], true) ||
                in_array($bus, ['usb', 'iscsi', 'network', 'smb', 'nfs'], true)) continue;

            $mount = strtoupper(str_replace('/', '\\', trim((string) ($volume['mountpoint'] ?? ''))));
            $device = trim((string) ($volume['device'] ?? ''));
            // No incorporar rutas UNC, montajes no identificados o unidades de red explícitas.
            if (strlen($mount) !== 3 || ! ctype_alpha($mount[0]) || $mount[1] !== ':' || $mount[2] !== '\\' || str_starts_with($device, '\\\\')) continue;
            $identity = $mount[0];
            if (isset($seen[$identity])) continue;

            $bytes = $this->integer($volume['total_bytes'] ?? null, 1024 ** 5);
            if ($bytes === null || $sum > PHP_INT_MAX - $bytes) continue;
            $seen[$identity] = true;
            $sum += $bytes;
            $found = true;
            $media = strtoupper(trim((string) ($volume['media_type'] ?? '')));
            if (in_array($media, ['SSD', 'HDD'], true)) $mediaTypes[$media] = true;
        }

        return [$found ? $sum : null, $mediaTypes ? implode(' + ', array_keys($mediaTypes)) : null];
    }

    /** Evita truncar valores como "4.8", "INF" o exponentes imprevistos. */
    private function integer(mixed $value, int $max): ?int
    {
        if (is_int($value)) return $value > 0 && $value <= $max ? $value : null;
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) return null;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        return $number === false ? null : $number;
    }

    private function frequency(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 && (float) $value <= 20000
            ? (float) $value : null;
    }

    private function average(array $rows, string $field, ?string $scope = null): array
    {
        $values = [];
        foreach ($rows as $row) {
            if ($scope !== null && $row['storage_scope'] !== $scope) continue;
            $value = $row[$field] ?? null;
            if ((is_int($value) || is_float($value)) && $value > 0) $values[] = $value;
        }
        return ['value' => $values ? array_sum($values) / count($values) : null, 'known' => count($values)];
    }
}
