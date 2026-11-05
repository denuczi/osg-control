<?php

namespace App\Domain\AI;

use App\Models\Device;
use App\Services\DeviceAvailability;
use Illuminate\Support\Str;

/**
 * Final server-side guard for model-selected operations. The LLM is never a
 * source of authority for action type, scope or device identifiers.
 */
final class AssistantActionPolicy
{
    public function __construct(private readonly DeviceAvailability $availability) {}

    /** @param array<string,mixed> $arguments */
    public function rejection(string $question, array $arguments, ?string $contextUuid = null): ?string
    {
        $instruction = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $question) ?? $question;
        $q = $this->normalize($instruction);
        $action = $arguments['action'] ?? null;
        if (! in_array($action, ['show_message', 'restart', 'shutdown'], true)) return 'accion_no_autorizada';
        if ($q === '' || preg_match('/^(?:no|nunca|jamas|como|que|cual|donde|cuando|por que|podria|se puede|puedo)\b/u', $q)
            || preg_match('/\b(?:quiero|necesito|prefiero)\s+(?:que\s+)?no\b/u', $q)) {
            return 'peticion_no_ejecutable';
        }
        // If the model proposes a different action than the user's words,
        // fail closed. Ambiguous or mixed commands also require clarification.
        $asked = [];
        if (preg_match('/\b(?:apaga|apagar|apagues|apaguen|apagal[oa]s?|apague|shutdown)\b/u', $q)) $asked[] = 'shutdown';
        if (preg_match('/\b(?:reinicia|reiniciar|reinicies|reinicien|reinicial[oa]s?|reinicie|reboot)\b/u', $q)) $asked[] = 'restart';
        if (preg_match('/\b(?:envia|enviar|enviale|enviales|manda|mandar|mandale|avisar|avisa|avisale|mostrar|mostra|comunicar|comunica|notificar|notifica)\b/u', $q)
            && preg_match('/\b(?:mensaje|aviso|notificacion|texto|informe)\b/u', $q)) $asked[] = 'show_message';
        if (count($asked) !== 1 || $asked[0] !== $action) return 'accion_no_coincide_con_solicitud';

        $all = ($arguments['all_online'] ?? false) === true;
        $uuids = $arguments['device_uuids'] ?? [];
        if (! is_array($uuids) || ($all && $uuids !== []) || (! $all && $uuids === [])) return 'destinatarios_no_verificados';
        $explicitAll = preg_match('/\b(?:todos?\s+los\s+(?:equipos|conectados|encendidos|prendidos)|todas?\s+las\s+(?:computadoras|compus|pcs)|a\s+todos|cada\s+(?:equipo|computadora|pc)\s+(?:conectad[oa]|encendid[oa]|prendid[oa]))\b/u', $q) === 1;
        $excludes = preg_match('/\b(?:excepto|menos|salvo|excluyendo|sin incluir|del laboratorio|de la oficina|del sector|del aula)\b/u', $q) === 1;
        if ($excludes || ($all && ! $explicitAll) || (! $all && $explicitAll)) return 'alcance_no_autorizado';
        if ($all) return null;
        if (count($uuids) !== count(array_unique($uuids)) || count($uuids) > 20) return 'destinatarios_no_verificados';

        $devices = Device::query()->whereIn('uuid', $uuids)->whereNull('revoked_at')
            ->whereNull('archived_at')->get(['uuid', 'name', 'hostname']);
        if ($devices->count() !== count($uuids)) return 'destinatarios_no_verificados';
        $explicit = [];
        foreach ($devices as $device) {
            foreach ([$device->uuid, $device->hostname, $device->name] as $identifier) {
                $term = $this->normalize((string) $identifier);
                if (mb_strlen($term) >= 3 && str_contains(' '.$q.' ', ' '.$term.' ')) {
                    $explicit[$device->uuid] = true;
                    break;
                }
            }
        }
        if (count($explicit) === count($uuids)) return null;
        if ($contextUuid !== null && count($uuids) === 1 && $uuids[0] === $contextUuid &&
            preg_match('/\b(?:este equipo|esta computadora|esta pc|equipo actual|computadora actual)\b/u', $q)) {
            // Context from a detail page may only authorize an explicit
            // reference to *this* device, never a different named computer.
            if (! preg_match('/\b(?:otra|otro|distinta|diferente)\s+(?:pc|equipo|computadora)\b/u', $q)) return null;
        }

        // A singular reference to the only connected device is verifiable
        // without allowing the model to pick an arbitrary online computer.
        if (count($uuids) === 1 && preg_match('/\b(?:equipo|computadora|compu|pc)\s+(?:conectad[oa]|prendid[oa]|encendid[oa]|en linea)\b/u', $q)) {
            $online = $this->availability->online(Device::query()->whereNull('archived_at'), $this->availability->cutoff())
                ->limit(2)->pluck('uuid')->all();
            if (count($online) === 1 && $online[0] === $uuids[0]) return null;
        }
        return 'destinatarios_no_verificados';
    }

    private function normalize(string $text): string
    {
        $text = strtolower(Str::ascii($text));
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9\s-]+/u', ' ', $text) ?? '') ?? '');
    }
}
