<?php

namespace App\Domain\AI;

use App\Models\Device;
use App\Services\DeviceAvailability;
use App\Services\DisplayTimezone;
use Illuminate\Support\Str;

/** Deterministic last-contact answers: the model never invents a time or status. */
final class AssistantLastSeenQuery
{
    public function __construct(
        private readonly DisplayTimezone $timezone,
        private readonly DeviceAvailability $availability,
    ) {}

    public function applies(string $question): bool
    {
        $q = $this->normalize($question);
        // Do not intercept USB, network traffic, processes or other timelines.
        if (preg_match('/\b(usb|pendrive|trafico|consumo|procesos|apagado|reinicio)\b/u', $q)) return false;
        return preg_match('/\b(ultima?|ultimo|cuando|hace cuanto)\b/u', $q) === 1
            && preg_match('/\b(vist[oa]|contacto|comunicacion|se conecto|conexion|conectad[oa]|actividad|ultima vez|reporte|sen[a]?l)\b/u', $q) === 1;
    }

    /** @return array{answer:string,evidence:array,proposal:null} */
    public function answer(string $question, ?string $contextUuid): array
    {
        $base = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        $q = $this->normalize($question);
        // Prefer a UUID explicitly present in the question to a panel context.
        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $question, $match)) {
            $device = (clone $base)->where('uuid', $match[0])->first();
            return $this->describe($device);
        }
        if ((clone $base)->count() > 500) {
            return $this->reply('El inventario supera el límite de búsqueda por nombre. Indicá el UUID del equipo para consultar su última comunicación.');
        }
        $matches = (clone $base)->get(['id', 'uuid', 'name', 'hostname', 'last_seen_at', 'is_demo', 'revoked_at'])
            ->filter(function (Device $device) use ($q): bool {
                foreach ([$device->name, $device->hostname] as $label) {
                    $needle = $this->normalize((string) $label);
                    if (mb_strlen($needle) >= 3 && str_contains(' '.$q.' ', ' '.$needle.' ')) return true;
                }
                return false;
            })->values();
        if ($matches->count() > 1) {
            return $this->reply('Encontré varios equipos mencionados en la consulta. Indicá un único hostname o UUID para consultar su última comunicación.');
        }
        if ($matches->count() === 1) return $this->describe($matches->first());
        if ($contextUuid !== null) return $this->describe((clone $base)->where('uuid', $contextUuid)->first());
        return $this->reply('Indicá el nombre, hostname o UUID del equipo del que querés conocer la última comunicación.');
    }

    private function describe(?Device $device): array
    {
        if ($device === null) return $this->reply('No encontré ese equipo entre los dispositivos registrados y autorizados.');
        $name = str_replace(['\\', '*', '`', '[', ']'], ['\\\\', '\\*', '\\`', '\\[', '\\]'], (string) $device->name);
        if ($device->last_seen_at === null) {
            return $this->reply("El equipo **{$name}** está registrado, pero no hay una última comunicación verificable.");
        }
        $local = $this->timezone->human($device->last_seen_at);
        $label = $this->timezone->label($device->last_seen_at);
        $status = $device->isOnline($this->availability->cutoff())
            ? 'Actualmente está conectado.'
            : 'Actualmente figura desconectado; el último contacto no indica por sí solo cuándo se apagó.';
        $demo = $device->is_demo ? ' Este equipo está marcado como demostración.' : '';
        return $this->reply("La última comunicación registrada de **{$name}** fue el **{$local}** ({$label}). {$status}{$demo}");
    }

    private function normalize(string $text): string
    {
        $q = strtolower(Str::ascii($text));
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9 -]+/u', ' ', $q) ?? '') ?? '');
    }

    private function reply(string $answer): array
    {
        return ['answer' => $answer, 'evidence' => [], 'proposal' => null];
    }
}
