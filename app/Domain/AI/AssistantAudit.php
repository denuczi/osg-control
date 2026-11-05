<?php

namespace App\Domain\AI;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/** Stores an actionable audit description without retaining raw chat prompts. */
final class AssistantAudit
{
    public function __construct(private readonly AssistantRuntime $runtime) {}

    /** @param list<string> $tools */
    public function consultation(int $userId, string $topic, array $tools = [], bool $hasProposal = false, ?string $question = null): void
    {
        AuditLog::query()->create([
            'user_id' => $userId,
            'event' => 'ai.consulted',
            'context' => [
                'topic' => $this->label($topic, $tools, $question),
                'tools' => array_values(array_unique($tools)),
                'action_proposed' => $hasProposal,
                'performance' => $this->runtime->stats(),
            ],
        ]);
    }

    public function outsideScope(int $userId): void
    {
        AuditLog::query()->create([
            'user_id' => $userId,
            'event' => 'ai.out_of_scope',
            'context' => ['topic' => 'Consulta fuera del ámbito de infraestructura'],
        ]);
    }

    /** @param list<string> $tools */
    private function label(string $topic, array $tools, ?string $question): string
    {
        return match ($topic) {
            'fleet_count' => 'Cantidad de computadoras registradas',
            'device_list' => 'Listado de computadoras registradas',
            'deployment_candidates' => 'Evaluación de equipos para un servidor Python',
            'report_request' => 'Generación de informe PDF',
            'ram_leader' => 'Comparación de memoria RAM instalada',
            'fleet_processes' => 'Procesos con mayor consumo de memoria',
            'software_compatibility' => 'Compatibilidad de equipos con GTA V',
            'network_usage' => 'Consumo de red de las computadoras',
            'usb_connections' => 'Conexiones recientes de almacenamiento USB',
            'usb_device_connections' => 'Conexiones USB de una computadora',
            'disk_space_advice' => 'Recomendaciones para liberar espacio en disco',
            'shutdown_history' => 'Revisión de apagados inesperados',
            'usage_ambiguous' => 'Consulta sobre utilización de los equipos',
            'assistant_identity' => 'Identidad y modelo del asistente',
            'browser_status' => 'Navegadores registrados en un equipo',
            'product_about' => 'Información sobre OSG Nodo Control',
            'product_technology' => 'Tecnologías de OSG Nodo Control',
            'action' => 'Preparación de una acción remota',
            'message_action' => 'Preparación de mensaje a una computadora',
            'action_help' => 'Consulta sobre envío de mensajes',
            'ambiguous_action' => 'Acción remota sin destinatario inequívoco',
            default => $this->labelFromQuestion($question) ?? $this->labelFromTools($tools),
        };
    }

    private function labelFromQuestion(?string $question): ?string
    {
        if ($question === null) return null;
        $q = strtolower(Str::ascii($question));
        if (preg_match('/\b(discos?|almacenamiento|espacio libre|ssd|hdd|volumenes?)\b/', $q)) {
            return 'Análisis de almacenamiento y espacio libre';
        }
        if (preg_match('/\b(conectad[oa]s?|desconectad[oa]s?|conexion|redes?|wifi)\b/', $q)) {
            return 'Estado de conexión y redes';
        }
        if (preg_match('/\b(procesos?|aplicaciones?|programas?)\b/', $q)) {
            return 'Procesos y aplicaciones de los equipos';
        }
        if (preg_match('/\b(antivirus|defender|firewall|seguridad|actualizaciones)\b/', $q)) {
            return 'Estado de seguridad y actualizaciones';
        }
        if (preg_match('/\b(cpu|procesador|nucleos|rendimiento)\b/', $q)) {
            return 'Procesador y rendimiento de equipos';
        }
        if (preg_match('/\b(ram|memoria)\b/', $q)) {
            return 'Memoria de los equipos';
        }
        return null;
    }

    /** @param list<string> $tools */
    private function labelFromTools(array $tools): string
    {
        $labels = [
            'fleet_overview' => 'Resumen del inventario',
            'find_devices' => 'Búsqueda y comparación de equipos',
            'rank_processors' => 'Comparación de procesadores',
            'fleet_top_processes' => 'Procesos con mayor consumo de memoria',
            'device_processes' => 'Consulta de procesos de una computadora',
            'software_compatibility' => 'Compatibilidad de software',
            'fleet_network_usage' => 'Consumo de red de las computadoras',
            'fleet_usb_connections' => 'Conexiones recientes de almacenamiento USB',
            'fleet_shutdown_events' => 'Revisión de apagados inesperados',
            'prepare_bulk_action' => 'Preparación de acción remota',
        ];
        $topics = array_values(array_unique(array_filter(array_map(
            static fn (string $tool): ?string => $labels[$tool] ?? null,
            $tools,
        ))));
        return $topics === [] ? 'Consulta técnica de infraestructura' : implode(' · ', array_slice($topics, 0, 3));
    }
}
