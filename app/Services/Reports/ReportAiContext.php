<?php

namespace App\Services\Reports;

/** Projection for AI, directly from source data (never from PDF or presentation DTOs). */
final class ReportAiContext
{
    private const EXCLUDED = [
        'uuid','hostname','serial','serialnumber','token','tokenhash','password',
        'productkey','partialproductkey','licensekey','activationkey','installationid','confirmationid','digitalproductid',
        'mac','macaddress','ip','ipaddress','address','addresses','ipv4','ipv6',
        'partnumber','usersid','pid','commanduuid','eventuuid','bootid','ssid','label',
    ];

    public function fromRaw(array $raw): array
    {
        // Inventory labels may contain personal names; identity is not needed for analysis.
        unset($raw['device']['name'], $raw['device']['hostname'], $raw['device']['uuid']);
        // Retain every data domain and its coverage metadata. Bound individual event
        // records for model context; aggregate counts are computed from ALL stored rows.
        $raw['system_events']['recent'] = array_slice($raw['system_events']['recent'] ?? [], 0, 80);
        $raw['system_events']['distribution'] = array_slice($raw['system_events']['distribution'] ?? [], 0, 120);
        $raw['media_events']['recent'] = array_slice($raw['media_events']['recent'] ?? [], 0, 30);
        $raw['system_events']['ai_recent_limit'] = 80;
        $raw['system_events']['ai_distribution_limit'] = 120;
        $raw['media_events']['ai_recent_limit'] = 30;
        $raw['event_monitoring'] = [
            'channels' => ['System', 'Application'],
            'mode' => 'event_ids_and_verified_providers_only',
            'coverage_percent' => null,
            'coverage_explanation' => 'El colector no mide la cobertura porcentual del registro completo de Windows; el porcentaje de cobertura de muestras CPU/RAM NO corresponde a eventos. Está prohibido estimar 1 %, 90 % u otra cobertura de eventos.',
            'collection_limits' => 'Marcadores persistentes, catálogo restringido de eventos, posibilidad de pérdida ante rotaciones y desconexiones prolongadas; no equivale a un SIEM.',
        ];
        $raw['privacy'] = ['scope' => 'telemetría técnica sin credenciales ni identificadores personales',
            'event_history' => 'muestra acotada + distribución global; ausencia de eventos no prueba ausencia de fallos',
            'unknown' => 'los valores nulos y datos desactualizados no representan cero; no estimar latencia ni limpieza de malware sin mediciones específicas'];
        return $this->filter($raw);
    }

    /** Preserve every evidence domain but bound individual records for the model context. */
    public function compact(array $context): array
    {
        foreach ([
            ['system_events', 'recent', 25],
            ['system_events', 'distribution', 80],
            ['media_events', 'recent', 15],
            ['device', 'processes', 20],
        ] as [$domain, $key, $limit]) {
            if (isset($context[$domain][$key]) && is_array($context[$domain][$key])) {
                $context[$domain][$key] = array_slice($context[$domain][$key], 0, $limit);
            }
        }
        if (isset($context['independent_evidence']['window_90_days']['event_types'])) {
            $context['independent_evidence']['window_90_days']['event_types'] =
                array_slice($context['independent_evidence']['window_90_days']['event_types'], 0, 100);
        }
        $context['privacy']['context_compacted'] = true;
        $context['privacy']['context_compaction_note'] = 'Se acotaron registros individuales por presupuesto; recuentos, distribuciones y cobertura permanecen disponibles.';

        return $context;
    }

    private function filter(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 12) return null;
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
                if (in_array($normalized, self::EXCLUDED, true)) continue;
                $out[$key] = $this->filter($item, $depth + 1);
            }
            return $out;
        }
        if (is_string($value)) {
            $sanitized = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP omitida]', $value) ?? '';
            $sanitized = preg_replace('/\b(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/', '[MAC omitida]', $sanitized) ?? '';
            $sanitized = preg_replace('/[A-Z]:\\\\[^\s;"<>]+/i', '[ruta omitida]', $sanitized) ?? '';
            $sanitized = preg_replace('/\b[\w.-]+@[\w.-]+\.[A-Za-z]{2,}\b/u', '[correo omitido]', $sanitized) ?? '';
            $sanitized = preg_replace('/\b[A-Za-z0-9_.-]+\\\\[A-Za-z0-9_.-]+\b/u', '[cuenta omitida]', $sanitized) ?? '';
            return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sanitized) ?? '', 0, 650);
        }
        return is_scalar($value) || $value === null ? $value : null;
    }
}
