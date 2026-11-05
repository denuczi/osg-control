<?php

namespace App\Domain\AI\Tools;

use App\Models\Device;
use App\Services\DeviceAvailability;
use Illuminate\Support\Str;

/** Verified requirements catalog. Never lets a model fabricate application requirements. */
final class SoftwareCompatibility
{
    private const SOURCE = 'https://support.rockstargames.com/articles/lMQXeP2Z1mN3g9oZiBZFR/grand-theft-auto-v-pc-system-requirements';

    private const EDITIONS = [
        'enhanced' => [
            'title' => 'GTA V Enhanced', 'ram_gb' => 8, 'free_gb' => 105, 'ssd_required' => true,
            'cpu' => 'Intel Core i7-4770 / AMD FX-9590',
            'gpu' => 'NVIDIA GTX 1630 (4 GB) / AMD RX 6400 (4 GB)',
        ],
        'legacy' => [
            'title' => 'GTA V Legacy', 'ram_gb' => 4, 'free_gb' => 125, 'ssd_required' => false,
            'cpu' => 'Intel Core 2 Quad Q6600 / AMD Phenom 9850',
            'gpu' => 'NVIDIA 9800 GT (1 GB) / AMD HD 4870 (1 GB)',
        ],
    ];

    public function __construct(private readonly DeviceAvailability $availability) {}

    public function evaluate(): array
    {
        $query = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        $total = (clone $query)->count();
        $limit = max(1, min(250, (int) config('reports.fleet_max_devices', 250)));
        if ($total > $limit) {
            return ['result' => ['error' => 'inventario_demasiado_grande', 'registered' => $total,
                'max_evaluable' => $limit, 'notes' => 'El inventario supera el límite de evaluación. No se presenta una clasificación parcial.']];
        }
        $devices = [];
        $cutoff = $this->availability->cutoff();
        $query->select(['id', 'uuid', 'name', 'hostname', 'platform', 'is_demo', 'last_seen_at',
            'last_sampled_at', 'memory_total_bytes'])
            ->with(['inventory:id,device_id,volumes,processor,captured_at'])
            ->chunkById(40, function ($batch) use (&$devices, $cutoff): void {
                foreach ($batch as $device) {
                    $volumes = $this->eligibleVolumes($device->inventory?->volumes ?? []);
                    $editionResults = [];
                    foreach (self::EDITIONS as $id => $spec) {
                        $ramBytes = $device->memory_total_bytes;
                        $ramStatus = $ramBytes === null || $ramBytes <= 0 ? 'unknown'
                            : ($ramBytes >= $spec['ram_gb'] * 1000000000 ? 'pass' : 'fail');
                        $disk = $this->evaluateStorage($volumes, $spec['free_gb'], $spec['ssd_required']);
                        // CPU/GPU models cannot safely be ranked solely by their reported names.
                        // Even if the OS family is known, Windows build requirements may not be.
                        $editionResults[$id] = [
                            'ram' => $ramStatus, 'storage' => $disk['status'],
                            'os' => 'unknown', 'cpu' => 'unknown', 'gpu' => 'unknown',
                            'free_gb' => $disk['free_gb'], 'media_type' => $disk['media_type'],
                            'status' => in_array('fail', [$ramStatus, $disk['status']], true) ? 'insufficient' : 'unverified',
                        ];
                    }
                    $devices[] = [
                        'uuid' => $device->uuid, 'name' => Str::limit((string) $device->name, 100, ''),
                        'online' => $device->isOnline($cutoff),
                        'ram_gib' => $device->memory_total_bytes !== null && $device->memory_total_bytes > 0
                            ? round($device->memory_total_bytes / (1024 ** 3), 2) : null,
                        'platform' => Str::limit((string) $device->platform, 120, ''),
                        'captured_at' => $device->inventory?->captured_at?->toIso8601String(),
                        'metrics_at' => $device->last_sampled_at?->toIso8601String(),
                        'editions' => $editionResults,
                    ];
                }
            });
        usort($devices, static fn (array $a, array $b): int =>
            strcmp($a['name'], $b['name']) ?: strcmp($a['uuid'], $b['uuid']));
        $result = [
            'software' => 'Grand Theft Auto V', 'registered' => $total,
            'source_name' => 'Rockstar Games — requisitos oficiales para PC', 'source_url' => self::SOURCE,
            'requirements' => self::EDITIONS, 'devices' => $devices,
            'notes' => 'Se comparan las últimas mediciones, incluso de equipos desconectados. '
                .'No se puede certificar compatibilidad sin verificar procesador, gráficos, edición de Windows y otros requisitos. '
                .'El espacio libre se evalúa por volumen y no se suma entre unidades. '
                .'La cifra de RAM exigida por el fabricante se expresa en GB decimales; la memoria del equipo se muestra en GiB.',
        ];
        $presentation = $result;
        unset($presentation['devices']);
        $presentation['compatibility_devices'] = $devices;
        return ['result' => $result, 'evidence' => [
            'kind' => 'compatibility', 'title' => 'Compatibilidad con GTA V', 'data' => $presentation,
        ]];
    }

    /** Distinguish independently mountable local drives from removable/network locations. */
    private function eligibleVolumes(mixed $data): array
    {
        if (! is_array($data)) return [];
        $byDrive = [];
        foreach ($data as $v) {
            if (! is_array($v) || ($v['removable'] ?? null) !== false) continue;
            $bus = strtolower((string) ($v['bus_type'] ?? ''));
            $category = strtolower((string) ($v['media_class'] ?? ''));
            if (in_array($bus, ['usb', 'iscsi', 'network', 'smb', 'nfs'], true) ||
                in_array($category, ['usb', 'optical', 'removable', 'network'], true)) continue;
            $mount = strtoupper(str_replace('/', '\\', trim((string) ($v['mountpoint'] ?? ''))));
            if (! preg_match('/^[A-Z]:\\\\$/D', $mount)) continue;
            if (str_starts_with((string) ($v['device'] ?? ''), '\\\\')) continue;
            $free = $this->bytes($v['free_bytes'] ?? null);
            if ($free === null) continue;
            $type = strtoupper((string) ($v['media_type'] ?? ''));
            if (! in_array($type, ['SSD', 'HDD'], true)) $type = null;
            // The same volume may have duplicate records; do not combine their capacities.
            $letter = $mount[0];
            if (isset($byDrive[$letter])) {
                $prior = $byDrive[$letter];
                $free = min($free, $prior['bytes']);
                // Conflicting observations must not certify an SSD.
                if ($type !== $prior['type']) $type = null;
            }
            $byDrive[$letter] = ['free_gb' => round($free / 1000000000, 2),
                'type' => $type, 'bytes' => $free];
        }
        return array_values($byDrive);
    }

    private function evaluateStorage(array $volumes, int $requiredGb, bool $requiresSsd): array
    {
        if ($volumes === []) return ['status' => 'unknown', 'free_gb' => null, 'media_type' => null];
        $largest = null;
        $matched = false;
        $hasUnknownMedia = false;
        foreach ($volumes as $volume) {
            if ($largest === null || $volume['bytes'] > $largest['bytes']) $largest = $volume;
            if ($volume['bytes'] >= $requiredGb * 1000000000) {
                if (! $requiresSsd || $volume['type'] === 'SSD') $matched = true;
                if ($requiresSsd && $volume['type'] === null) $hasUnknownMedia = true;
            }
        }
        $status = $matched ? 'pass' : ($hasUnknownMedia ? 'unknown' : 'fail');
        return ['status' => $status, 'free_gb' => $largest['free_gb'] ?? null,
            'media_type' => $largest['type'] ?? null];
    }

    private function bytes(mixed $value): ?int
    {
        if (is_int($value)) return $value >= 0 && $value <= 1024 ** 5 ? $value : null;
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]*)$/D', $value)) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1024 ** 5]]);
        return $parsed === false ? null : $parsed;
    }
}
