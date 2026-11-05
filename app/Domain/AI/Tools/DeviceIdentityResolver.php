<?php

namespace App\Domain\AI\Tools;

use App\Models\Device;
use Illuminate\Support\Str;

/**
 * Resolución de identificadores de inventario: no interpreta estar desconectado
 * como ausencia del equipo y nunca selecciona arbitrariamente entre homónimos.
 */
final class DeviceIdentityResolver
{
    private const MAX_CANDIDATES = 500;

    /**
     * @return array{status: string, matches: list<array{id: int, uuid: string, name: string, hostname: string}>, total: int}
     */
    public function matches(string $reference): array
    {
        $needle = $this->normalized($reference);
        if (mb_strlen($needle) < 3) {
            return ['status' => 'invalid', 'matches' => [], 'total' => 0];
        }

        $base = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        // Un UUID o hostname exacto puede resolverse sin recorrer una flota grande.
        $exactIdentifier = trim($reference);
        if (Str::isUuid($exactIdentifier) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/i', $exactIdentifier)) {
            $direct = (clone $base)->where(static fn ($query) => $query
                ->where('uuid', $exactIdentifier)
                ->orWhereRaw('LOWER(hostname) = ?', [strtolower($exactIdentifier)]))
                ->get(['id', 'uuid', 'name', 'hostname'])
                ->map(static fn (Device $device): array => [
                    'id' => (int) $device->id, 'uuid' => $device->uuid,
                    'name' => $device->name, 'hostname' => $device->hostname,
                ])->all();
            if ($direct !== []) {
                return ['status' => count($direct) === 1 ? 'found' : 'ambiguous',
                    'matches' => $direct, 'total' => count($direct)];
            }
        }
        if ((clone $base)->count() > self::MAX_CANDIDATES) {
            // No se debe informar «no existe» si no se ha consultado todo el inventario.
            return ['status' => 'limit_exceeded', 'matches' => [], 'total' => 0];
        }

        $exact = [];
        $partial = [];
        $base->select(['id', 'uuid', 'name', 'hostname'])->chunkById(100, function ($devices) use ($needle, &$exact, &$partial): void {
            foreach ($devices as $device) {
                $name = $this->normalized((string) $device->name);
                $hostname = $this->normalized((string) $device->hostname);
                $uuid = $this->normalized((string) $device->uuid);
                $candidate = [
                    'id' => (int) $device->id,
                    'uuid' => (string) $device->uuid,
                    'name' => (string) $device->name,
                    'hostname' => (string) $device->hostname,
                ];
                if ($needle === $name || $needle === $hostname || $needle === $uuid) {
                    $exact[] = $candidate;
                } elseif ($this->containsTokens($name, $needle) || $this->containsTokens($hostname, $needle)) {
                    $partial[] = $candidate;
                }
            }
        });

        // Un nombre/hostname exacto tiene prioridad sobre coincidencias parciales.
        $matches = $exact !== [] ? $exact : $partial;
        usort($matches, static fn (array $a, array $b): int => strcmp($a['uuid'], $b['uuid']));
        $count = count($matches);

        return [
            'status' => $count === 0 ? 'not_found' : ($count === 1 ? 'found' : 'ambiguous'),
            'matches' => $matches,
            'total' => $count,
        ];
    }

    private function containsTokens(string $haystack, string $needle): bool
    {
        return str_contains(' '.$haystack.' ', ' '.$needle.' ');
    }

    private function normalized(string $text): string
    {
        $ascii = strtolower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';
        $tokens = array_filter(explode(' ', trim($text)), static fn (string $part): bool =>
            ! in_array($part, ['de', 'del', 'la', 'el', 'los', 'las'], true));
        return trim(implode(' ', $tokens));
    }
}
