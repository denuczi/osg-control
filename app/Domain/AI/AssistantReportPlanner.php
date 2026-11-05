<?php

namespace App\Domain\AI;

use App\Domain\AI\Tools\DeviceIdentityResolver;
use App\Models\Device;
use App\Services\Reports\ReportRequestService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Interpreta solicitudes explícitas de informe; solo agenda generadores reales. */
final class AssistantReportPlanner
{
    public function __construct(
        private readonly ReportRequestService $requests,
        private readonly DeviceIdentityResolver $identities,
    ) {}

    public function requestsReport(string $question): bool
    {
        $q = $this->normalize($question);
        if (! preg_match('/\b(inventarios?|informes?|reportes?|pdf)\b/u', $q)) return false;
        if (preg_match('/\b(no quiero|no generes|no crees|no descargues|no exportes|sin generar|sin crear|no hagas|solo explicame|quiero saber como)\b/u', $q)) return false;
        // Consultar por la función de informes no autoriza generarlos.
        if (preg_match('/^(que|cual|como|donde|por que|para que|puedo|se puede|cuantos|incluye|explica)\b/u', $q)) return false;
        return preg_match('/\b(quiero|quisiera|gustaria|necesito|solicito|genera\w*|crea\w*|prepara\w*|descarga\w*|exporta\w*|dame|haz|hace|saca\w*|arma\w*|hacer)\b/u', $q) === 1;
    }

    /** @return array{answer:string,evidence:array,proposal:null,report?:array} */
    public function prepare(int $actor, string $question, ?string $contextUuid): array
    {
        $q = $this->normalize($question);
        $target = $this->deviceReference($question);
        $plural = preg_match('/\b(general|global|flota|parque|dispositivos|computadoras|equipos)\b/u', $q) === 1;
        // Inventario sin identificador es el documento general del parque.
        $fleet = $plural || ($target === null && preg_match('/\binventario\b/u', $q) && $contextUuid === null);

        if ($fleet) {
            try {
                $report = $this->requests->fleet($actor);
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() === 429) return $this->answer('Se alcanzó el límite diario de inventarios. Podés descargar un informe previamente generado desde Equipos.');
                throw $exception;
            }
            return [
                ...$this->answer('Solicité el **inventario PDF de los equipos registrados**. Se está preparando con los datos reales del sistema; podrás descargarlo cuando esté listo.'),
                'report' => [
                    'kind' => 'fleet', 'uuid' => $report->uuid, 'status' => $report->status,
                    'status_url' => route('fleet-reports.status', $report, false),
                ],
            ];
        }

        if ($target !== null) {
            $matches = $this->identities->matches($target);
            if ($matches['status'] === 'ambiguous') {
                return $this->answer('Hay varias computadoras que coinciden con ese nombre. Indicá el hostname o identificador exacto para generar el informe correcto.');
            }
            if ($matches['status'] === 'limit_exceeded') {
                return $this->answer('El inventario supera el límite de búsqueda por nombres. Indicá el hostname o identificador exacto del equipo.');
            }
            if ($matches['status'] !== 'found') {
                return $this->answer('No encontré esa computadora entre los equipos registrados. Comprobá su nombre o hostname en la sección Equipos.');
            }
            $targetUuid = $matches['matches'][0]['uuid'];
        } else {
            $targetUuid = $contextUuid;
        }
        if ($targetUuid === null) return $this->answer('Indicá el nombre o hostname de la computadora para generar su informe, o pedí el **inventario general** de todos los equipos.');

        $device = Device::query()->where('uuid', $targetUuid)->whereNull('revoked_at')->whereNull('archived_at')->first();
        if ($device === null || $device->is_demo) return $this->answer('No se puede generar un informe de esa computadora. Verificá que esté registrada y autorizada.');

        try {
            $report = $this->requests->device($actor, $device);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 429) return $this->answer('Se alcanzó el límite diario de informes. Revisá los informes existentes del equipo.');
            throw $exception;
        }
        return [
            ...$this->answer('Solicité el **informe PDF de '. $this->escapeInline($device->name).'**. Se está preparando; podrás descargarlo cuando finalice.'),
            'report' => [
                'kind' => 'device', 'uuid' => $report->uuid, 'status' => $report->status,
                'status_url' => route('reports.status', $report, false),
            ],
        ];
    }

    /** Devuelve solo una referencia que el administrador incluyó explícitamente. */
    private function deviceReference(string $question): ?string
    {
        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $question, $match)) return $match[0];
        if (preg_match('/\b(?:DESKTOP|LAPTOP|LAB-PC|ADMIN-PC|PC)-[A-Z0-9-]{1,}\b/i', $question, $match)) return $match[0];
        if (! preg_match('/\b(?:informe|reporte|inventario)\s+(?:pdf\s+)?(?:de|del|para|sobre)\s+(.+)$/iu', $question, $match)) return null;
        $name = trim(preg_replace('/\s+(?:en\s+pdf|por\s+favor|ahora)\s*\.?$/iu', '', $match[1]) ?? $match[1], " \t\n\r\0\x0B\"'¿?¡!");
        $name = trim(preg_replace('/^(?:(?:la|el|los|las)\s+)?(?:computadora|compu|equipo|pc|notebook|netbook)\s+(?:de\s+)?/iu', '', $name) ?? $name);
        $generic = $this->normalize($name);
        if (preg_match('/^(?:la |el )?(?:computadora|compu|equipo|pc|notebook|netbook|dispositivo)$/u', $generic)) return null;
        return mb_strlen($name) >= 3 ? $name : null;
    }

    private function escapeInline(string $text): string
    {
        return str_replace(['\\', '*', '`', '[', ']'], ['\\\\', '\\*', '\\`', '\\[', '\\]'], $text);
    }

    private function normalize(string $question): string
    {
        $q = strtolower(Str::ascii($question));
        $q = preg_replace('/[^a-z0-9\s-]+/u', ' ', $q) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    }

    private function answer(string $message): array
    {
        return ['answer' => $message, 'evidence' => [], 'proposal' => null];
    }
}
