<?php

namespace App\Domain\AI;

use App\Domain\AI\Tools\DeviceIdentityResolver;
use App\Models\Device;
use App\Services\DeviceAvailability;
use Illuminate\Support\Str;

/** Interpret recorded process snapshots without claiming live window detection. */
final class AssistantBrowserInspector
{
    private const BROWSERS = [
        'chrome.exe' => 'Google Chrome',
        'msedge.exe' => 'Microsoft Edge',
        'firefox.exe' => 'Mozilla Firefox',
        'brave.exe' => 'Brave',
        'opera.exe' => 'Opera',
        'opera_gx.exe' => 'Opera GX',
        'vivaldi.exe' => 'Vivaldi',
        'chromium.exe' => 'Chromium',
        'iexplore.exe' => 'Internet Explorer',
        'librewolf.exe' => 'LibreWolf',
    ];

    public function __construct(
        private readonly DeviceAvailability $availability,
        private readonly DeviceIdentityResolver $resolver,
    ) {}

    /** @return array{answer:string,evidence:array,proposal:null} */
    public function inspect(string $target, ?string $reference, ?string $panelUuid): array
    {
        if ($target === 'all_online') {
            return $this->reply('Para consultar navegadores por equipo, indicá una computadora concreta. La consulta general de procesos no comprueba ventanas abiertas.');
        }
        if ($target === 'single_online') {
            $devices = $this->availability->online(Device::query()->whereNull('archived_at'), $this->availability->cutoff())
                ->orderBy('id')->limit(2)->get($this->columns());
            if ($devices->count() === 0) return $this->reply('No hay equipos conectados para revisar su última captura de procesos. Puedo consultar una computadora desconectada por su nombre, aclarando la antigüedad de sus datos.');
            if ($devices->count() !== 1) return $this->reply('Hay varias computadoras conectadas. Indicá el nombre o hostname de la que querés consultar; no voy a elegir una arbitrariamente.');
            $device = $devices->first();
        } elseif ($target === 'context' && $panelUuid !== null) {
            $device = $this->findByUuid($panelUuid);
        } elseif ($target === 'named' && is_string($reference) && $reference !== '') {
            $resolution = $this->resolver->matches($reference);
            if ($resolution['status'] === 'ambiguous') return $this->reply('Encontré varios equipos con ese nombre. Indicá su hostname o identificador para consultar el correcto.');
            if ($resolution['status'] !== 'found') return $this->reply('No encontré un equipo registrado que coincida de forma inequívoca con ese nombre.');
            $device = $this->findByUuid($resolution['matches'][0]['uuid']);
        } else {
            return $this->reply('¿Qué computadora querés consultar? Indicá su nombre o preguntá por el único equipo conectado, si corresponde.');
        }
        if ($device === null) return $this->reply('No encontré el equipo en el inventario operativo.');

        $snapshot = is_array($device->processes) ? $device->processes : [];
        $found = [];
        $matchedProcesses = [];
        foreach (array_slice($snapshot, 0, 100) as $process) {
            if (! is_array($process) || ! is_string($process['name'] ?? null)) continue;
            $executable = strtolower(basename(str_replace('\\', '/', trim($process['name']))));
            if (isset(self::BROWSERS[$executable])) {
                $label = self::BROWSERS[$executable];
                $found[$label] = ($found[$label] ?? 0) + 1;
                $matchedProcesses[] = [
                    'pid' => is_int($process['pid'] ?? null) ? $process['pid'] : null,
                    'name' => $executable,
                    'memory_mb' => is_numeric($process['memory_mb'] ?? null) && is_finite((float) $process['memory_mb'])
                        ? round((float) $process['memory_mb'], 1) : null,
                ];
            }
        }
        $capturedAt = $device->processes_sampled_at;
        $date = $capturedAt?->copy()->timezone(app(\App\Services\DisplayTimezone::class)->current())->format('d/m/Y H:i');
        $online = $device->isOnline($this->availability->cutoff());
        $name = str_replace(['\\', '*', '`', '[', ']'], ['\\\\', '\\*', '\\`', '\\[', '\\]'], Str::limit((string) $device->name, 100, ''));
        $prefix = "En **{$name}** ";
        if ($date === null) {
            $answer = $prefix.'no hay una captura de procesos con fecha verificable. No puedo confirmar qué navegador está abierto.';
        } elseif ($found !== []) {
            $names = implode(', ', array_map(static fn (string $label, int $count): string => "**{$label}** ({$count} proceso(s))", array_keys($found), array_values($found)));
            $answer = $prefix."se registraron procesos de {$names} en la captura del **{$date}** (hora configurada). "
                .'Eso demuestra que sus procesos estaban registrados en ese momento, no que exista ahora una ventana abierta.';
        } else {
            $answer = $prefix."no se identificaron Chrome, Edge, Firefox, Brave u otros navegadores conocidos en la captura parcial del **{$date}**. "
                .'**No puedo asegurar que estén cerrados**: el agente conserva solo una muestra de procesos.';
        }
        if (! $online) $answer .= ' Actualmente el equipo está desconectado.';
        elseif ($capturedAt === null || $capturedAt->lt(now()->subMinutes(5))) $answer .= ' Está conectado, pero la captura de procesos no es reciente.';
        else $answer .= ' El equipo está conectado y la captura es reciente, aunque no es una inspección de ventanas en vivo.';

        return $this->reply($answer, $matchedProcesses === [] ? [] : [[
            'kind' => 'processes', 'title' => 'Navegadores identificados en la última captura',
            'data' => [
                'device_uuid' => $device->uuid, 'device_name' => $device->name,
                'captured_at' => $capturedAt?->toIso8601String(),
                'snapshot_is_recent' => $online && $capturedAt !== null && $capturedAt->gte(now()->subMinutes(5)),
                'processes_stored' => count($snapshot), 'total_running_processes' => null,
                'top_processes' => array_slice($matchedProcesses, 0, 15),
                'notes' => $found !== [] ? 'Navegadores detectados en una muestra parcial; no confirma ventanas abiertas ahora.' : 'La ausencia en la muestra parcial no confirma que un navegador esté cerrado.',
            ],
        ]]);
    }

    private function findByUuid(string $uuid): ?Device
    {
        return Device::query()->where('uuid', $uuid)->whereNull('revoked_at')->whereNull('archived_at')
            ->first($this->columns());
    }

    private function columns(): array
    {
        return ['id', 'uuid', 'name', 'hostname', 'processes', 'processes_sampled_at', 'last_seen_at', 'revoked_at', 'is_demo'];
    }

    private function reply(string $answer, array $evidence = []): array
    {
        return ['answer' => $answer, 'evidence' => $evidence, 'proposal' => null];
    }
}
