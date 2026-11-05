<?php

namespace App\Domain\AI;

use Illuminate\Support\Str;

/** No publica afirmaciones libres del modelo como si fueran inventario verificado. */
final class AssistantGroundingBoundary
{
    public function requiresEvidence(string $question, ?string $deviceUuid): bool
    {
        $text = strtolower(Str::ascii($question));
        $text = trim(preg_replace('/[^a-z0-9\s-]+/u', ' ', $text) ?? '');
        // La pregunta sobre tecnologías genéricas no consulta el parque real.
        $deviceTerms = preg_match('/\b(equipos?|computadoras?|compus?|pcs?|notebooks?|netbooks?|dispositivos?|inventario|parque informatico|flota)\b/u', $text) === 1;
        $fleetFacts = preg_match('/\b(registrad[oa]s?|tengo|tenemos|hay|nombres?|lista|listado|ram|cpu|procesadores?|discos?|almacenamiento|conectad[oa]s?|desconectad[oa]s?|apt[oa]s?|sirven?|compatib|instalad[oa]s?|ejecutar|desplegar|procesos?|rinde|rendimiento|estado|informacion|datos|disponibles?)\b/u', $text) === 1;
        return ($deviceTerms && $fleetFacts) || ($deviceUuid !== null && $fleetFacts);
    }

    /** Un texto libre sin herramientas no puede introducir identificadores del inventario. */
    public function containsUnverifiedFleetClaims(string $answer): bool
    {
        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $answer)) return true;
        $q = strtolower(Str::ascii($answer));
        return preg_match('/\b\d+\s+(?:equipos|computadoras|dispositivos)\s+(?:registrados?|conectados?|desconectados?)\b/u', $q) === 1;
    }

    /** Nombres internos de funciones no pertenecen a la conversación del administrador. */
    public function publicLanguage(string $answer): string
    {
        $names = [
            'find_devices' => 'la búsqueda de equipos',
            'device_processes' => 'la consulta de procesos del equipo',
            'fleet_top_processes' => 'la comparación de procesos',
            'fleet_network_usage' => 'la consulta de tráfico de red',
            'fleet_usb_connections' => 'la consulta de conexiones USB',
            'metrics_at' => 'fecha de la última medición',
            'online_only' => 'solo equipos conectados',
            'max_ram_gib' => 'RAM máxima',
            'min_ram_gib' => 'RAM mínima',
        ];
        foreach ($names as $internal => $friendly) {
            $answer = str_ireplace('`'.$internal.'`', $friendly, $answer);
        }
        return str_ireplace(array_keys($names), array_values($names), $answer);
    }

    /**
     * Respuesta redactada con el resultado del servidor, nunca con nombres inventados por el LLM.
     * @param list<array<string,mixed>> $evidence
     */
    public function describe(array $evidence): string
    {
        if ($evidence === []) {
            return 'No pude obtener datos verificables del inventario para responder esa consulta. No voy a presentar computadoras ni capacidades que no estén registradas.';
        }
        foreach ($evidence as $item) {
            $data = $item['data'] ?? [];
            if (! is_array($data)) continue;
            if (in_array($item['kind'] ?? null, ['devices', 'processors'], true)) {
                $found = (int) ($data['total_matching'] ?? $data['registered'] ?? count($data['devices'] ?? []));
                if ($found === 0) return 'No encontré equipos que coincidan con el filtro consultado. Esto no significa que el inventario general esté vacío.';
                if (($data['truncated'] ?? false) === true) {
                    return 'Encontré **'.$found.' '.($found === 1 ? 'equipo' : 'equipos').'** para la consulta, pero la lista adjunta está limitada. No voy a presentar un resultado parcial como si fuera el inventario completo.';
                }
                return 'Encontré **'.$found.' '.($found === 1 ? 'equipo' : 'equipos').'** con información registrada. Sus nombres y características verificadas aparecen en la tabla adjunta. Las últimas mediciones pueden estar desactualizadas.';
            }
            if (($item['kind'] ?? null) === 'summary') {
                $registered = (int) ($data['registered'] ?? 0);
                return 'El inventario registra **'.$registered.' '.($registered === 1 ? 'equipo' : 'equipos').'**. Los indicadores adjuntos proceden de la información almacenada en el servidor.';
            }
        }
        return 'Presento los datos que el sistema pudo verificar en los resultados adjuntos. Si alguna medición no está disponible, no debe interpretarse como cero ni como una comprobación en tiempo real.';
    }
}
