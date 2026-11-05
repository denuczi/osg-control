<?php

namespace App\Domain\AI;

use App\Models\Device;
use App\Models\MetricSample;

/**
 * Read-only, bounded explanation of memory pressure and idle RAM use.
 * Cached telemetry is evidence; it cannot identify the root cause of RAM usage
 * without Windows counters for cache, commit and paging.
 */
final class AssistantMemoryAdvisor
{
    /** @return array{answer:string,evidence:array,proposal:null} */
    public function answer(?string $deviceUuid): array
    {
        $why = 'Que Windows use alrededor del **50 % de la RAM sin ventanas abiertas** no implica necesariamente un problema: '
            .'los servicios y aplicaciones en segundo plano, el antivirus, la caché de archivos, el núcleo y la memoria comprimida '
            .'pueden seguir utilizando memoria. Windows puede liberar parte de la memoria en espera cuando otras aplicaciones la necesitan. '
            .'Un porcentaje por sí solo **no demuestra una fuga de memoria**.';
        $steps = ' Para investigar si existe presión real, compara el uso a lo largo del tiempo y revisa '
            .'**Memoria disponible, En caché y Confirmada** en el Administrador de tareas o el Monitor de recursos. '
            .'Si el consumo aumenta continuamente, aparece paginación intensa o el equipo se ralentiza, '
            .'entonces conviene examinar los procesos y servicios responsables.';

        if ($deviceUuid === null) {
            return $this->result($why.' Para contrastarlo con las métricas reales, abre el asistente desde la ficha del equipo que quieres analizar.'.$steps);
        }

        $device = Device::query()->where('uuid', $deviceUuid)
            ->whereNull('revoked_at')->whereNull('archived_at')
            ->first(['id', 'name', 'last_seen_at', 'last_sampled_at', 'memory_percent',
                'memory_total_bytes', 'memory_used_bytes', 'processes', 'processes_sampled_at', 'is_demo']);
        if ($device === null) {
            return $this->result('El equipo seleccionado ya no está disponible o autorizado para consultar. '.$why.$steps);
        }

        $name = $this->safeLabel((string) $device->name);
        $memory = self::percentage($device->memory_percent);
        $fresh = $device->last_sampled_at !== null
            && $device->last_sampled_at->betweenIncluded(now()->subMinutes(5), now()->addMinute());
        $current = ' En **'.$name.'**';
        if ($memory === null || $device->last_sampled_at === null) {
            $current .= ' no hay una medición válida de uso de RAM para contrastar ese porcentaje.';
        } else {
            $current .= ' la última muestra registra **'.self::format($memory).' % de RAM utilizada**';
            $total = $device->memory_total_bytes;
            $used = $device->memory_used_bytes;
            if (is_int($total) && $total > 0 && is_int($used) && $used >= 0 && $used <= $total) {
                $current .= ' (aproximadamente '.self::format($used / (1024 ** 3)).' de '
                    .self::format($total / (1024 ** 3)).' GiB)';
            }
            $current .= $fresh ? '. Se trata de una muestra reciente, no de una medición continua.'
                : '. **Esta medición está desactualizada**; no representa necesariamente el estado actual.';
        }

        // Indexed by (device_id, recorded_at): at most 120 rows read for one
        // device from the last hour. Never scan the entire telemetry history.
        $series = MetricSample::query()->where('device_id', $device->id)
            ->whereBetween('recorded_at', [now()->subHour(), now()->addMinute()])
            ->orderByDesc('recorded_at')->limit(120)->get(['memory_percent', 'recorded_at']);
        $values = [];
        foreach ($series as $sample) {
            $value = self::percentage($sample->memory_percent);
            if ($value !== null) $values[] = $value;
        }
        if (count($values) >= 3) {
            $min = min($values);
            $max = max($values);
            $current .= ' En **'.count($values).' muestras registradas en la última hora**, el uso estuvo entre **'
                .self::format($min).' % y '.self::format($max).' %**.';
            if ($min >= 45 && $max <= 55) {
                $current .= ' Durante ese intervalo se mantuvo cerca de 50 %, pero eso no demuestra que haya sido así siempre.';
            } else {
                $current .= ' Las mediciones muestran variación; no se puede afirmar que se mantenga fijo en 50 %.';
            }
        } elseif ($memory !== null) {
            $current .= ' No hay suficientes muestras recientes para confirmar que permanezca cerca de 50 % de forma sostenida.';
        }

        if ($device->processes_sampled_at !== null
            && $device->processes_sampled_at->betweenIncluded(now()->subMinutes(5), now()->addMinute())
            && is_array($device->processes)) {
            $top = [];
            foreach (array_slice($device->processes, 0, 250) as $process) {
                if (! is_array($process) || ! is_string($process['name'] ?? null)) continue;
                $mb = $process['memory_mb'] ?? null;
                if (! is_numeric($mb) || ! is_finite((float) $mb) || (float) $mb < 0 || (float) $mb > 1048576) continue;
                $top[] = ['name' => $this->safeLabel($process['name']), 'mb' => (float) $mb];
            }
            usort($top, static fn (array $a, array $b): int => $b['mb'] <=> $a['mb']);
            if ($top !== []) {
                $labels = array_map(static fn (array $p): string => '**'.$p['name'].'** ('.self::format($p['mb']).' MiB)', array_slice($top, 0, 3));
                $current .= ' En la última captura de procesos, los mayores consumos residentes registrados fueron '
                    .implode(', ', $labels).'. **Esta captura puede ser parcial** y la suma de memorias residentes '
                    .'no explica por sí sola el uso total de RAM de Windows.';
            }
        }

        if ($device->is_demo) {
            $current .= ' **Es un equipo de demostración**; estos valores no acreditan una medición real.';
        } elseif (! $fresh || ! $device->isOnline()) {
            $current .= ' No puedo asegurar que el estado actual del equipo coincida con esta captura.';
        }

        return $this->result($why.$current.$steps);
    }

    /** @return array{answer:string,evidence:array,proposal:null} */
    private function result(string $answer): array
    {
        return ['answer' => $answer, 'evidence' => [], 'proposal' => null];
    }

    private static function percentage(mixed $value): ?float
    {
        if (! is_numeric($value)) return null;
        $number = (float) $value;
        return is_finite($number) && $number >= 0 && $number <= 100 ? $number : null;
    }

    private static function format(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    private function safeLabel(string $value): string
    {
        $safe = preg_replace('/[^\pL\pN ._()-]+/u', ' ', $value) ?? '';
        return mb_substr(trim($safe), 0, 100);
    }
}
