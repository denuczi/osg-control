<?php

namespace App\Http\Controllers\Web;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Services\DeviceAvailability;
use App\Services\MonitorSettings;
use App\Support\CommandViewData;
use App\Support\DeviceViewData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    private const PREVIEW_LIMIT = 100;

    private const ACTIVITY_EVENT_LABELS = [
        'device.renamed' => 'Equipo renombrado',
        'command.created' => 'Orden creada',
        'command.completed' => 'Orden completada',
        'command.failed' => 'Orden fallida',
        'command.unknown' => 'Resultado sin confirmar',
        'command.expired' => 'Orden vencida',
        'settings.updated' => 'Configuración de monitoreo modificada',
        'settings.timezone.updated' => 'Zona horaria modificada',
        'reports.ai.settings.updated' => 'Configuración de informes modificada',
        'report.requested' => 'Informe PDF solicitado',
        'fleet.report.requested' => 'Inventario general solicitado',
        'device.pairing.approved' => 'Equipo autorizado',
        'device.pairing.rejected' => 'Solicitud de vinculación rechazada',
        'device.revoked' => 'Acceso del equipo revocado',
        'device.archived' => 'Equipo archivado',
        'device.restored' => 'Equipo restaurado al inventario',
        'device.deleted' => 'Equipo eliminado',
        'ai.consulted' => 'Consulta a OSG Nodo AI',
        'ai.out_of_scope' => 'Consulta fuera del ámbito del asistente',
        'ai.action_prepared' => 'Acción propuesta por el asistente',
        'ai.action_confirmed' => 'Acción del asistente autorizada',
        'ai.action_cancelled' => 'Propuesta del asistente descartada',
        'ai.runtime_settings_updated' => 'Límites del asistente actualizados',
    ];

    /** No contiene prompts, nombres de modelos ni datos personales de las computadoras. */
    private function assistantActivityDetail(array $context): ?string
    {
        $parts = [];
        if (is_array($context['tools'] ?? null) && $context['tools'] !== []) {
            $parts[] = 'Consulta técnica: '.count($context['tools']).' herramienta(s)';
        }
        $stats = $context['performance'] ?? null;
        if (is_array($stats)) {
            if (is_numeric($stats['duration_ms'] ?? null)) {
                $parts[] = 'Duración: '.number_format(max(0, (int) $stats['duration_ms']) / 1000, 2, ',', '.').' s';
            }
            if (is_numeric($stats['model_requests'] ?? null)) {
                $parts[] = 'Llamadas al modelo: '.(int) $stats['model_requests'];
            }
            $tokens = (int) ($stats['input_tokens_reported'] ?? 0) + (int) ($stats['output_tokens_reported'] ?? 0);
            if ($tokens > 0) {
                $parts[] = 'Tokens informados por el proveedor: '.number_format($tokens, 0, ',', '.');
            }
        }
        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function snapshot(DeviceAvailability $availability, MonitorSettings $settings): array
    {
        $cutoff = $availability->cutoff();
        $base = Device::query()->whereNull('revoked_at');
        $online = $availability->online(Device::query(), $cutoff);
        return [
            'devices' => (clone $base)->orderBy('name')->limit(self::PREVIEW_LIMIT)->get()
                ->map(fn (Device $device) => DeviceViewData::from($device, false, $cutoff))->values(),
            'total' => (clone $base)->count(),
            'online' => (clone $online)->count(),
            'demo' => (clone $base)->where('is_demo', true)->count(),
            'avgCpu' => (clone $online)->whereNotNull('cpu_percent')->avg('cpu_percent'),
            'refreshSeconds' => $settings->dashboardRefresh(),
        ];
    }

    public function index(DeviceAvailability $availability, MonitorSettings $settings): Response
    {
        $snapshot = $this->snapshot($availability, $settings);
        $commands = DeviceCommand::query()->with('device')->latest()->limit(6)->get();
        return Inertia::render('Dashboard', [
            'devices' => $snapshot['devices'],
            'recentCommands' => $commands->map(fn (DeviceCommand $command) => CommandViewData::from($command)),
            'stats' => ['total' => $snapshot['total'], 'online' => $snapshot['online'],
                'demo' => $snapshot['demo'], 'avgCpu' => $snapshot['avgCpu']],
            'refreshSeconds' => $snapshot['refreshSeconds'],
        ]);
    }

    public function status(DeviceAvailability $availability, MonitorSettings $settings): JsonResponse
    {
        $snapshot = $this->snapshot($availability, $settings);
        return response()->json([
            'next_refresh_seconds' => $snapshot['refreshSeconds'],
            'total' => $snapshot['total'], 'online' => $snapshot['online'],
            'demo' => $snapshot['demo'], 'average_cpu' => $snapshot['avgCpu'],
            'devices' => $snapshot['devices'],
        ])->header('Cache-Control', 'no-store');
    }

    public function deviceStatuses(Request $request, DeviceAvailability $availability, MonitorSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:15'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $cutoff = $availability->cutoff();
        $devices = Device::query()->whereNull('revoked_at')->whereIn('uuid', $data['uuids'])->get();
        return response()->json([
            'next_refresh_seconds' => $settings->dashboardRefresh(),
            'devices' => $devices->map(fn (Device $device) => DeviceViewData::from($device, false, $cutoff))->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function activity(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:important,all,reports,commands,devices,settings,assistant'],
            'event' => ['nullable', 'string', 'max:80'],
            'device' => ['nullable', 'uuid'],
            'actor' => ['nullable', 'string', 'max:20'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $category = (string) ($validated['category'] ?? 'important');
        $event = trim((string) ($validated['event'] ?? ''));
        $deviceUuid = trim((string) ($validated['device'] ?? ''));
        $actor = trim((string) ($validated['actor'] ?? ''));

        $logs = AuditLog::query()
            ->with(['device', 'user'])
            // Suppress repetitive PDF requests by default; retain every event in SQLite.
            // An explicit event filter always takes precedence over the category.
            ->when($event === '' && $category !== 'all', function (Builder $query) use ($category): void {
                match ($category) {
                    'important' => $query->whereNotIn('event', ['report.requested', 'fleet.report.requested']),
                    'reports' => $query->where(function (Builder $inner): void {
                        $inner->where('event', 'like', 'report.%')->orWhere('event', 'fleet.report.requested');
                    }),
                    'commands' => $query->where('event', 'like', 'command.%'),
                    'assistant' => $query->where('event', 'like', 'ai.%'),
                    'devices' => $query->where('event', 'like', 'device.%'),
                    'settings' => $query->where(function (Builder $inner): void {
                        $inner->whereIn('event', ['settings.updated', 'settings.timezone.updated'])
                            ->orWhere('event', 'reports.ai.settings.updated')
                            ->orWhere('event', 'ai.runtime_settings_updated');
                    }),
                    default => null,
                };
            })
            ->when($event !== '', fn (Builder $query) => $query->where('event', $event))
            ->when($deviceUuid !== '', function (Builder $query) use ($deviceUuid): void {
                $query->whereHas('device', fn (Builder $deviceQuery) => $deviceQuery->where('uuid', $deviceUuid));
            })
            ->when($actor !== '', function (Builder $query) use ($actor): void {
                if ($actor === 'system') {
                    $query->whereNull('user_id');
                    return;
                }
                if (ctype_digit($actor)) {
                    $query->where('user_id', (int) $actor);
                }
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $escaped = '%'.addcslashes($search, "%_\\").'%';
                $query->where(function (Builder $inner) use ($escaped): void {
                    $inner->where('event', 'like', $escaped)
                        ->orWhere('context', 'like', $escaped)
                        ->orWhereHas('device', fn (Builder $deviceQuery) => $deviceQuery
                            ->where('name', 'like', $escaped)
                            ->orWhere('hostname', 'like', $escaped))
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('name', 'like', $escaped));
                });
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $commandUuids = $logs->getCollection()->map(fn (AuditLog $log) => $log->context['command_uuid'] ?? null)
            ->filter(fn ($uuid) => is_string($uuid) && $uuid !== '')->unique()->values()->all();
        $commands = DeviceCommand::query()->whereIn('uuid', $commandUuids)
            ->get(['uuid', 'action', 'parameters', 'result_message'])->keyBy('uuid');

        $deviceOptions = Device::query()->whereNull('revoked_at')->orderBy('name')->limit(200)->get(['uuid', 'name'])
            ->map(fn (Device $device) => ['value' => $device->uuid, 'label' => $device->name])
            ->values()->all();
        $actorOptions = array_merge([
            ['value' => 'system', 'label' => 'Sistema'],
        ], User::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
            ->values()->all());

        return Inertia::render('Activity/Index', [
            'logs' => $logs->through(function (AuditLog $log) use ($commands): array {
                $context = $log->context ?? [];
                $command = $commands->get($context['command_uuid'] ?? '');
                $action = $command?->action ?? ($context['action'] ?? null);
                $messageText = $action === 'show_message'
                    ? ($command?->parameters['message'] ?? $context['parameters']['message'] ?? null)
                    : null;

                $summary = match ($log->event) {
                    'ai.consulted' => 'Consulta: '.(is_string($context['topic'] ?? null) ? $context['topic'] : 'Consulta técnica de infraestructura').'.',
                    'ai.out_of_scope' => 'El asistente rechazó una consulta ajena a la administración de infraestructura; no se guardó el texto.',
                    'ai.action_prepared' => 'Se preparó una propuesta de operación remota pendiente de autorización.',
                    'ai.action_confirmed' => 'Se autorizó una operación propuesta por el asistente.',
                    'ai.action_cancelled' => 'Se descartó una propuesta; ninguna orden fue enviada desde ella.',
                    'ai.runtime_settings_updated' => 'Se modificaron los límites de tiempo y uso del asistente.',
                    'report.requested' => 'Se solicitó generar un informe PDF del equipo.',
                    'fleet.report.requested' => 'Se solicitó exportar el inventario general de computadoras.',
                    'device.pairing.approved' => 'Un administrador autorizó una nueva computadora.',
                    'device.pairing.rejected' => 'Un administrador rechazó una solicitud de vinculación.',
                    'device.revoked' => 'Se revocaron las credenciales del agente. El historial permanece guardado.',
                    'device.archived' => 'El equipo fue archivado y sus credenciales fueron revocadas.',
                    'device.restored' => 'El equipo volvió al inventario, pero la credencial sigue revocada.',
                    'device.deleted' => 'Se eliminaron el equipo y sus registros asociados del servidor.',
                    'reports.ai.settings.updated' => 'Se actualizó la configuración de conclusiones mediante IA para los informes PDF.',
                    'settings.updated' => 'Se modificaron los intervalos generales de monitoreo y retención.',
                    'settings.timezone.updated' => 'Se modificó la zona horaria de presentación; la telemetría histórica conserva sus instantes originales.',
                    'device.renamed' => 'Se actualizó el nombre visible del equipo en el panel.',
                    'command.created' => is_string($action) ? 'Se registró una orden remota pendiente para el agente.' : 'Se registró una orden remota.',
                    'command.completed' => 'La computadora informó que completó la orden remota.',
                    'command.failed' => 'La computadora informó que la orden remota falló.',
                    'command.unknown' => 'La computadora devolvió un resultado no concluyente para la orden remota.',
                    'command.expired' => 'La orden venció antes de que el agente pudiera ejecutarla.',
                    default => null,
                };

                $detail = match ($log->event) {
                    'device.renamed' => is_string($context['name'] ?? null) ? 'Nuevo nombre: '.$context['name'] : null,
                    'settings.timezone.updated' => is_string($context['after'] ?? null) ? 'Zona seleccionada: '.$context['after'] : null,
                    'device.deleted' => is_string($context['device_name'] ?? null) ? 'Equipo eliminado: '.$context['device_name'] : null,
                    'ai.consulted' => $this->assistantActivityDetail($context),
                    'report.requested' => ($context['ai_requested'] ?? false)
                        ? 'Incluye conclusión mediante IA si el proveedor responde correctamente.'
                        : 'Se generará el PDF sin conclusión automática mediante IA.',
                    default => null,
                };

                return [
                    'id' => $log->id,
                    'event' => $log->event,
                    'event_label' => self::ACTIVITY_EVENT_LABELS[$log->event] ?? $log->event,
                    'device_name' => $log->device?->name,
                    'device_uuid' => $log->device?->uuid,
                    'actor' => $log->user?->name ?? 'Sistema',
                    'action' => is_string($action) ? $action : null,
                    'message_text' => is_string($messageText) ? $messageText : null,
                    'summary' => $summary,
                    'detail' => $detail,
                    'result_message' => str_starts_with($log->event, 'command.') && $log->event !== 'command.created'
                        ? ($context['message'] ?? $command?->result_message) : null,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            }),
            'filters' => [
                'q' => $search,
                'category' => $category,
                'event' => $event,
                'device' => $deviceUuid,
                'actor' => $actor,
            ],
            'eventOptions' => collect(self::ACTIVITY_EVENT_LABELS)->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
            ])->values()->all(),
            'deviceOptions' => $deviceOptions,
            'actorOptions' => $actorOptions,
        ]);
    }
}
