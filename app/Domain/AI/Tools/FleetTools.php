<?php

namespace App\Domain\AI\Tools;

use App\Models\AiActionProposal;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\DeviceAvailability;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Consultas acotadas y propuestas sin ejecución: el modelo nunca recibe acceso al ORM. */
final class FleetTools
{
    public function __construct(
        private readonly DeviceAvailability $availability,
        private readonly SoftwareCompatibility $softwareCompatibility,
        private readonly FleetTelemetry $telemetry,
        private readonly DeviceIdentityResolver $identityResolver,
    ) {}

    public function definitions(bool $includeActions = true): array
    {
        $uuidArray = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20];
        $definitions = [
            $this->definition('fleet_overview', 'Resumen de equipos registrados, conectados, promedios y memoria RAM instalada. Úsala para responder preguntas generales.', [
                'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false,
            ]),
            $this->definition('find_devices', 'Busca equipos por nombre/hostname/UUID, RAM o uso del volumen principal. Devuelve máximo 20, junto con fecha de medición y conteo total.', [
                'type' => 'object', 'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Nombre, hostname o UUID; vacío para todos.'],
                    'sort' => ['type' => 'string', 'enum' => ['ram_desc', 'ram_asc', 'disk_full', 'name']],
                    'online_only' => ['type' => 'boolean'],
                    'min_ram_gib' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1024],
                    'max_ram_gib' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1024],
                ], 'additionalProperties' => false,
            ]),
            $this->definition('rank_processors', 'Ordena hasta 250 equipos por núcleos físicos, RAM y procesadores lógicos declarados. Es un criterio de especificaciones, no un benchmark de rendimiento.', [
                'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false,
            ]),
            $this->definition('fleet_performance_diagnostics', 'Indicios de presión de CPU, RAM y disco en la última muestra de equipos, con antigüedad y especificaciones. No mide el rendimiento sostenido ni compara procesos.', [
                'type' => 'object', 'properties' => [
                    'device_uuid' => ['type' => 'string', 'description' => 'UUID del equipo de la ficha actual; omitir para toda la flota.'],
                ], 'additionalProperties' => false,
            ]),
            $this->definition('fleet_top_processes', 'Compara procesos por consumo de RAM usando las últimas capturas almacenadas. Incluye equipos desconectados salvo que se pida explícitamente solo los conectados.', [
                'type' => 'object', 'properties' => [
                    'online_only' => ['type' => 'boolean', 'description' => 'Solo cuando el usuario pida explícitamente equipos conectados.'],
                ], 'additionalProperties' => false,
            ]),
            $this->definition('device_processes', 'Obtiene hasta 15 procesos con más memoria reportados en la última captura de un equipo. NO es un recuento total de procesos y NO prueba que sigan ejecutándose.', [
                'type' => 'object', 'properties' => [
                    'device_uuid' => ['type' => 'string', 'description' => 'UUID exacto obtenido previamente mediante find_devices.'],
                ], 'required' => ['device_uuid'], 'additionalProperties' => false,
            ]),
            $this->definition('fleet_network_usage', 'Tráfico de red registrado por equipo y en total. Incluye equipos desconectados; no equivale a consumo de Internet facturado.', [
                'type' => 'object', 'properties' => ['period' => ['type' => 'string', 'enum' => ['today', 'yesterday', '7d', '30d', 'month']]], 'additionalProperties' => false,
            ]),
            $this->definition('fleet_shutdown_events', 'Indicios de apagados anteriores no limpios de Windows, eventos 41/6008; no inferir causas ni ausencia de fallas.', [
                'type' => 'object', 'properties' => ['period' => ['type' => 'string', 'enum' => ['today', 'yesterday', '7d', '30d', 'month']]], 'additionalProperties' => false,
            ]),
            $this->definition('fleet_usb_connections', 'Conexiones registradas de unidades de almacenamiento USB; excluye detecciones iniciales y medios ópticos. Incluye equipos desconectados.', [
                'type' => 'object', 'properties' => [
                    'period' => ['type' => 'string', 'enum' => ['today', 'yesterday', '7d', '30d', 'month']],
                    'device_uuid' => ['type' => 'string', 'description' => 'Solo un UUID validado previamente del inventario.'],
                ], 'additionalProperties' => false,
            ]),
            $this->definition('prepare_bulk_action', 'Prepara, sin ejecutar, un mensaje, reinicio o apagado de equipos concretos. Necesita aprobación del administrador. No usar con destinatarios ambiguos.', [
                'type' => 'object', 'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['show_message', 'restart', 'shutdown']],
                    'device_uuids' => $uuidArray,
                    'all_online' => ['type' => 'boolean', 'description' => 'Si true, incluye TODOS los equipos realmente conectados (máximo 20); nunca un subconjunto silencioso.'],
                    'message' => ['type' => 'string', 'maxLength' => 500],
                ], 'required' => ['action'], 'additionalProperties' => false,
            ]),
        ];
        return $includeActions ? $definitions : array_values(array_filter($definitions,
            static fn (array $item): bool => $item['function']['name'] !== 'prepare_bulk_action'));
    }

    private function definition(string $name, string $description, array $parameters): array
    {
        return ['type' => 'function', 'function' => compact('name', 'description', 'parameters')];
    }

    /** @return array{result:array<string,mixed>, evidence?:array<string,mixed>, proposal?:array<string,mixed>} */
    public function execute(string $name, array $args, int $userId): array
    {
        try {
            return match ($name) {
                'fleet_overview' => $this->overview($args),
                'find_devices' => $this->find($args),
                'device_processes' => $this->processes($args),
                'fleet_top_processes' => $this->fleetProcesses($args),
                'fleet_performance_diagnostics' => $this->performanceDiagnostics($args),
                'rank_processors' => $this->processors($args),
                'software_compatibility' => $this->softwareCompatibilityTool($args),
                'fleet_network_usage' => $this->networkUsageTool($args),
                'fleet_shutdown_events' => $this->shutdownEventsTool($args),
                'fleet_usb_connections' => $this->usbConnectionsTool($args),
                'prepare_bulk_action' => $this->prepare($args, $userId),
                default => ['result' => ['error' => 'herramienta_no_disponible']],
            };
        } catch (ValidationException $exception) {
            return ['result' => ['error' => 'argumentos_invalidos', 'details' => $exception->errors()]];
        }
    }

    /**
     * Bounded and read-only: compares measured pressure, not executable names.
     * Missing or old samples are never treated as healthy measurements.
     */
    private function performanceDiagnostics(array $args): array
    {
        $this->check($args, ['device_uuid' => ['sometimes', 'required', 'uuid']]);
        $query = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        if (isset($args['device_uuid'])) $query->where('uuid', $args['device_uuid']);
        $total = (clone $query)->count();
        $limit = max(1, min(250, (int) config('reports.fleet_max_devices', 250)));
        if ($total > $limit) return ['result' => [
            'error' => 'inventario_demasiado_grande', 'registered' => $total,
            'max_evaluable' => $limit, 'note' => 'No se clasifica una flota incompleta como si fuera total.',
        ]];
        $cutoff = $this->availability->cutoff();
        $rows = [];
        $query->with('inventory:id,device_id,processor,captured_at')
            ->select(['id','uuid','name','hostname','last_seen_at','last_sampled_at',
                'cpu_percent','memory_percent','disk_percent','memory_total_bytes',
                'disk_free_bytes','is_demo'])
            ->chunkById(40, function ($batch) use (&$rows, $cutoff): void {
                foreach ($batch as $device) {
                    $valid = static function ($value): ?float {
                        if (! is_numeric($value)) return null;
                        $number = (float) $value;
                        return is_finite($number) && $number >= 0 && $number <= 100 ? round($number, 1) : null;
                    };
                    $cpu = $valid($device->cpu_percent);
                    $ram = $valid($device->memory_percent);
                    $disk = $valid($device->disk_percent);
                    $sample = $device->last_sampled_at;
                    $fresh = $sample !== null && $sample->betweenIncluded(now()->subMinutes(5), now()->addMinute());
                    $indicators = [];
                    if ($fresh && $cpu !== null && $cpu >= 85) $indicators[] = 'CPU elevada en la última muestra';
                    if ($fresh && $ram !== null && $ram >= 90) $indicators[] = 'RAM elevada en la última muestra';
                    if ($disk !== null && $disk >= 90) $indicators[] = 'Unidad principal con poco espacio libre';
                    $rows[] = [
                        'uuid' => $device->uuid, 'name' => Str::limit((string) $device->name,100,''),
                        'hostname' => Str::limit((string) $device->hostname,120,''),
                        'online' => $device->isOnline($cutoff), 'demo' => (bool) $device->is_demo,
                        'sample_fresh' => $fresh, 'captured_at' => $sample?->toIso8601String(),
                        'cpu_percent' => $cpu, 'ram_percent' => $ram, 'disk_used_percent' => $disk,
                        'ram_gib' => $device->memory_total_bytes === null ? null : round($device->memory_total_bytes / (1024 ** 3), 2),
                        'disk_free_gib' => $device->disk_free_bytes === null ? null : round($device->disk_free_bytes / (1024 ** 3), 2),
                        'processor' => is_string(data_get($device->inventory?->processor, 'name'))
                            ? Str::limit(data_get($device->inventory?->processor, 'name'), 110, '') : null,
                        'physical_cores' => data_get($device->inventory?->processor, 'physical_cores'),
                        'indicators' => $indicators,
                    ];
                }
            });
        usort($rows, static function (array $a, array $b): int {
            $severity = static fn (array $r): int => count($r['indicators']) * 100
                + (in_array('RAM elevada en la última muestra', $r['indicators'], true) ? 20 : 0)
                + (in_array('CPU elevada en la última muestra', $r['indicators'], true) ? 10 : 0);
            return ($severity($b) <=> $severity($a)) ?: strcmp($a['uuid'], $b['uuid']);
        });
        $result = [
            'registered' => $total, 'devices' => array_slice($rows, 0, 20),
            'returned' => min(20, count($rows)), 'truncated' => count($rows) > 20,
            'flagged' => count(array_filter($rows, static fn ($r): bool => $r['indicators'] !== [])),
            'notes' => 'Indicadores de presión, no diagnósticos definitivos. CPU y RAM solo se evalúan si la muestra tiene 5 minutos o menos. El espacio en disco se basa en la última captura y puede estar desactualizado. No se consulta el ranking de procesos.',
        ];
        // A device summary uses the same structured device evidence as existing views.
        return ['result' => $result, 'evidence' => ['kind' => 'devices', 'title' => 'Evaluación de recursos de los equipos',
            'data' => ['devices' => array_map(static fn ($r) => [
                'uuid' => $r['uuid'], 'name' => $r['name'], 'hostname' => $r['hostname'],
                'online' => $r['online'], 'cpu_percent_last_sample' => $r['cpu_percent'],
                'ram_percent_last_sample' => $r['ram_percent'],
                'primary_disk_used_percent' => $r['disk_used_percent'],
                'ram_gib' => $r['ram_gib'], 'metrics_at' => $r['captured_at'],
            ], array_slice($rows, 0, 20)), 'total_matching' => $total, 'truncated' => $total > 20]]];
    }

    private function networkUsageTool(array $args): array
    {
        $this->check($args, ['period' => ['sometimes', 'in:today,yesterday,7d,30d,month']]);
        $result = $this->telemetry->network($args['period'] ?? '7d');
        return ['result' => $result, 'evidence' => ['kind' => 'network', 'title' => 'Tráfico de red registrado', 'data' => [...$result, 'network_devices' => $result['devices']]]];
    }

    private function shutdownEventsTool(array $args): array
    {
        $this->check($args, ['period' => ['sometimes', 'in:today,yesterday,7d,30d,month']]);
        $result = $this->telemetry->shutdowns($args['period'] ?? '7d');
        return ['result' => $result, 'evidence' => ['kind' => 'shutdowns', 'title' => 'Indicios de apagados inesperados', 'data' => [...$result, 'shutdown_devices' => $result['devices']]]];
    }

    private function usbConnectionsTool(array $args): array
    {
        $this->check($args, [
            'period' => ['sometimes', 'in:today,yesterday,7d,30d,month'],
            'device_uuid' => ['sometimes', 'uuid'],
        ]);
        $result = $this->telemetry->usbConnections($args['period'] ?? '7d', $args['device_uuid'] ?? null);
        return ['result' => $result, 'evidence' => [
            'kind' => 'usb_connections', 'title' => 'Conexiones de almacenamiento USB', 'data' => $result,
        ]];
    }

    private function softwareCompatibilityTool(array $args): array
    {
        $this->check($args, []);
        return $this->softwareCompatibility->evaluate();
    }

    private function overview(array $args): array
    {
        $this->check($args, []);
        $base = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        $online = $this->availability->online(clone $base, $this->availability->cutoff())->count();
        $stats = (clone $base)->selectRaw('COUNT(*) AS total, COUNT(memory_total_bytes) AS memory_known, AVG(memory_total_bytes) AS memory_average')->first();
        $result = [
            'captured_at' => now()->toIso8601String(),
            'registered' => (int) $stats->total,
            'online' => $online,
            'offline_or_demo' => max(0, (int) $stats->total - $online),
            'average_ram_gib' => $stats->memory_average !== null ? round((float) $stats->memory_average / (1024 ** 3), 2) : null,
            'ram_measured_devices' => (int) $stats->memory_known,
            'notes' => 'RAM instalada basada en mediciones disponibles; los equipos sin datos no se consideran ceros. Equipos de demostración no cuentan como conectados para recibir órdenes.',
        ];
        return ['result' => $result, 'evidence' => ['title' => 'Estado del inventario', 'kind' => 'summary', 'data' => $result]];
    }

    private function find(array $args): array
    {
        $this->check($args, [
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'in:ram_desc,ram_asc,disk_full,name'],
            'online_only' => ['sometimes', 'boolean'],
            'min_ram_gib' => ['sometimes', 'integer', 'between:0,1024'],
            'max_ram_gib' => ['sometimes', 'integer', 'between:0,1024'],
        ]);
        $query = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        $search = trim((string) ($args['search'] ?? ''));
        $normalizedMatch = null;
        if ($search !== '') {
            // SQLite LIKE no es insensible a tildes: "Felix" no encuentra "Félix".
            // Resolver el término solo cuando la búsqueda directa no devuelve filas.
            // ESCAPE is explicit and consistent on SQLite/PostgreSQL.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $filter = static fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                ->orWhereRaw("hostname LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                ->orWhere('uuid', '=', $search);
            if ((clone $query)->where($filter)->exists()) {
                $query->where($filter);
            } else {
                $normalizedMatch = $this->identityResolver->matches($search);
                if ($normalizedMatch['status'] === 'limit_exceeded') {
                    return ['result' => ['error' => 'busqueda_demasiado_amplia',
                        'note' => 'El inventario supera el límite de resolución por nombre. Buscá por hostname o UUID.']];
                }
                $query->whereIn('id', array_column($normalizedMatch['matches'], 'id'));
            }
        }
        if (($args['online_only'] ?? false) === true) {
            $query = $this->availability->online($query, $this->availability->cutoff());
        }
        if (isset($args['min_ram_gib'])) $query->where('memory_total_bytes', '>=', $args['min_ram_gib'] * 1024 ** 3);
        if (isset($args['max_ram_gib'])) $query->where('memory_total_bytes', '<=', $args['max_ram_gib'] * 1024 ** 3);
        $total = (clone $query)->count();
        $sort = $args['sort'] ?? 'name';
        if ($sort === 'ram_desc') $query->orderByDesc('memory_total_bytes');
        elseif ($sort === 'ram_asc') $query->orderByRaw('memory_total_bytes IS NULL')->orderBy('memory_total_bytes');
        elseif ($sort === 'disk_full') $query->orderByRaw('disk_percent IS NULL')->orderByDesc('disk_percent');
        else $query->orderBy('name');
        $cutoff = $this->availability->cutoff();
        $rows = $query->orderBy('uuid')->limit(20)->with([
            'inventory:id,device_id,processor,volumes,captured_at',
            'diagnostic:id,device_id,hardware,captured_at',
        ])->get([
            'id', 'uuid', 'name', 'hostname', 'platform', 'last_seen_at', 'last_sampled_at',
            'memory_total_bytes', 'disk_percent', 'disk_free_bytes', 'cpu_percent', 'memory_percent',
            'revoked_at', 'is_demo',
        ])->map(fn (Device $device): array => [
            'uuid' => $device->uuid,
            'name' => Str::limit($device->name, 100, ''),
            'hostname' => Str::limit($device->hostname, 120, ''),
            'system' => Str::limit($device->platform, 120, ''),
            'online' => $device->isOnline($cutoff),
            'demo' => (bool) $device->is_demo,
            'cpu_name' => is_string(data_get($device->inventory?->processor, 'name'))
                ? Str::limit((string) data_get($device->inventory?->processor, 'name'), 110, '') : null,
            'physical_cores' => data_get($device->inventory?->processor, 'physical_cores'),
            'logical_processors' => data_get($device->inventory?->processor, 'logical_processors'),
            'processor_captured_at' => $device->inventory?->captured_at?->toIso8601String(),
            'battery_present' => data_get($device->diagnostic?->hardware, 'status') === 'ok'
                ? data_get($device->diagnostic?->hardware, 'data.BatteryPresent') : null,
            'ram_gib' => $device->memory_total_bytes !== null ? round($device->memory_total_bytes / (1024 ** 3), 2) : null,
            'primary_disk_used_percent' => $device->disk_percent,
            'primary_disk_free_gib' => $device->disk_free_bytes !== null ? round($device->disk_free_bytes / (1024 ** 3), 2) : null,
            'cpu_percent_last_sample' => $device->cpu_percent,
            'ram_percent_last_sample' => $device->memory_percent,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'metrics_at' => $device->last_sampled_at?->toIso8601String(),
        ])->all();
        $result = [
            'total_matching' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows),
            'devices' => $rows,
            'notes' => 'La ocupación corresponde a la unidad principal informada y no necesariamente al disco C:. Las cifras proceden de la última medición disponible; algunos equipos pueden estar desconectados y sus datos desactualizados.',
        ];
        return ['result' => $result, 'evidence' => ['title' => 'Equipos encontrados', 'kind' => 'devices', 'data' => $result]];
    }

    private function processors(array $args): array
    {
        $this->check($args, []);
        $limit = min(250, max(1, (int) config('reports.fleet_max_devices', 250)));
        $base = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        $total = (clone $base)->count();
        if ($total > $limit) return ['result' => [
            'error' => 'inventario_demasiado_grande', 'registered' => $total,
            'max_evaluable' => $limit, 'note' => 'No se muestra una clasificación incompleta como si fuera total.',
        ]];
        $rows = [];
        $cutoff = $this->availability->cutoff();
        $base->select(['id', 'uuid', 'name', 'hostname', 'platform', 'memory_total_bytes', 'is_demo', 'last_seen_at', 'revoked_at'])->with([
            'inventory:id,device_id,processor,captured_at',
        ])->chunkById(40, function ($batch) use (&$rows, $cutoff): void {
            foreach ($batch as $device) {
                $cpu = $device->inventory?->processor ?? [];
                $cores = data_get($cpu, 'physical_cores');
                $logical = data_get($cpu, 'logical_processors');
                $rows[] = [
                    'uuid' => $device->uuid, 'name' => $device->name, 'hostname' => $device->hostname,
                    'online' => $device->isOnline($cutoff), 'demo' => (bool) $device->is_demo,
                    'cpu_name' => is_string(data_get($cpu, 'name')) ? Str::limit((string) data_get($cpu, 'name'), 100, '') : null,
                    'physical_cores' => $this->strictCoreCount($cores),
                    'logical_processors' => $this->strictCoreCount($logical),
                    'ram_gib' => $device->memory_total_bytes !== null ? round($device->memory_total_bytes / (1024 ** 3), 2) : null,
                    'processor_captured_at' => $device->inventory?->captured_at?->toIso8601String(),
                ];
            }
        });
        usort($rows, static function ($a, $b): int {
            foreach (['physical_cores', 'ram_gib', 'logical_processors'] as $field) {
                $result = ($b[$field] ?? -1) <=> ($a[$field] ?? -1);
                if ($result !== 0) return $result;
            }
            return strcmp($a['uuid'], $b['uuid']);
        });
        $result = [
            'registered' => $total, 'returned' => min(20, count($rows)),
            'truncated' => count($rows) > 20, 'devices' => array_slice($rows, 0, 20),
            'criteria' => 'Núcleos físicos, RAM instalada y procesadores lógicos; NO es una prueba de rendimiento ni una comparación definitiva entre arquitecturas.',
        ];
        return ['result' => $result, 'evidence' => ['title' => 'Comparación de procesadores y RAM', 'kind' => 'processors', 'data' => $result]];
    }

    private function processes(array $args): array
    {
        $this->check($args, ['device_uuid' => ['required', 'uuid']]);
        $device = Device::query()->where('uuid', $args['device_uuid'])->whereNull('revoked_at')
            ->whereNull('archived_at')->first(['uuid', 'name', 'last_seen_at', 'processes', 'processes_sampled_at', 'is_demo', 'revoked_at']);
        if (! $device) return ['result' => ['error' => 'equipo_no_encontrado']];
        $processes = is_array($device->processes) ? $device->processes : [];
        usort($processes, static fn ($a, $b): int => (float) ($b['memory_mb'] ?? 0) <=> (float) ($a['memory_mb'] ?? 0));
        $items = [];
        foreach (array_slice($processes, 0, 15) as $process) {
            if (! is_array($process)) continue;
            $items[] = [
                'pid' => is_int($process['pid'] ?? null) ? $process['pid'] : null,
                'name' => Str::limit((string) ($process['name'] ?? 'Sin nombre'), 100, ''),
                'memory_mb' => is_numeric($process['memory_mb'] ?? null) ? round((float) $process['memory_mb'], 1) : null,
            ];
        }
        $result = [
            'device_uuid' => $device->uuid, 'device_name' => $device->name,
            'online' => $device->isOnline($this->availability->cutoff()),
            'captured_at' => $device->processes_sampled_at?->toIso8601String(),
            'snapshot_is_recent' => $device->processes_sampled_at?->greaterThanOrEqualTo(now()->subMinutes(5)) ?? false,
            'processes_stored' => count($processes),
            'total_running_processes' => null,
            'top_processes' => $items,
            'notes' => 'Esta es una captura parcial de procesos, NO el total de procesos de Windows. El nombre de ejecutable no demuestra la presencia de documentos abiertos, ni que el proceso siga activo.',
        ];
        return ['result' => $result, 'evidence' => ['title' => 'Procesos de '.$device->name, 'kind' => 'processes', 'data' => $result]];
    }

    /**
     * Compara las últimas capturas, no la cantidad total de procesos de Windows.
     * No consulta relaciones por equipo y conserva solo los mayores 15 valores en memoria.
     */
    private function fleetProcesses(array $args): array
    {
        $this->check($args, ['online_only' => ['sometimes', 'boolean']]);
        $base = Device::query()->whereNull('revoked_at')->whereNull('archived_at');
        if (($args['online_only'] ?? false) === true) {
            $base = $this->availability->online($base, $this->availability->cutoff());
        }
        $total = (clone $base)->count();
        $maxDevices = max(1, min(250, (int) config('reports.fleet_max_devices', 250)));
        if ($total > $maxDevices) {
            return ['result' => [
                'error' => 'inventario_demasiado_grande',
                'registered' => $total,
                'max_evaluable' => $maxDevices,
                'note' => 'No se presenta una clasificación parcial como si incluyera todos los equipos.',
            ]];
        }

        $leaders = [];
        $sampled = 0;
        $cutoff = $this->availability->cutoff();
        $base->select(['id', 'uuid', 'name', 'last_seen_at', 'is_demo', 'revoked_at',
            'processes', 'processes_sampled_at'])->chunkById(40, function ($batch) use (&$leaders, &$sampled, $cutoff): void {
                foreach ($batch as $device) {
                    $snapshot = is_array($device->processes) ? $device->processes : [];
                    if ($snapshot === []) continue;
                    $hasProcess = false;
                    foreach (array_slice($snapshot, 0, 100) as $process) {
                        if (! is_array($process)) continue;
                        $memory = $process['memory_mb'] ?? null;
                        if (! is_numeric($memory)) continue;
                        $memory = (float) $memory;
                        if (! is_finite($memory) || $memory <= 0 || $memory > 1048576) continue;
                        $hasProcess = true;
                        $leaders[] = [
                            'device_uuid' => $device->uuid,
                            'device_name' => Str::limit((string) $device->name, 100, ''),
                            'online' => $device->isOnline($cutoff),
                            'captured_at' => $device->processes_sampled_at?->toIso8601String(),
                            'name' => Str::limit((string) ($process['name'] ?? 'Sin nombre'), 100, ''),
                            'pid' => is_int($process['pid'] ?? null) ? $process['pid'] : null,
                            'memory_mb' => round($memory, 1),
                        ];
                    }
                    if ($hasProcess) $sampled++;
                    // Evita acumular cientos de miles de procesos al crecer la flota.
                    usort($leaders, static fn (array $a, array $b): int =>
                        ($b['memory_mb'] <=> $a['memory_mb']) ?: strcmp($a['device_uuid'], $b['device_uuid']));
                    $leaders = array_slice($leaders, 0, 15);
                }
            });
        $result = [
            'registered' => $total,
            'online_only' => ($args['online_only'] ?? false) === true,
            'devices_with_samples' => $sampled,
            'returned' => count($leaders),
            'fleet_top_processes' => $leaders,
            'notes' => 'Comparación de muestras parciales almacenadas, que pueden ser antiguas. '
                .'La memoria residente no equivale al consumo exclusivo de una aplicación. '
                .'Las capturas pueden ser antiguas y no demuestran que los procesos sigan activos.',
        ];
        return ['result' => $result, 'evidence' => [
            'title' => 'Procesos con más memoria entre equipos', 'kind' => 'fleet_processes', 'data' => $result,
        ]];
    }

    private function prepare(array $args, int $userId): array
    {
        $this->check($args, [
            'action' => ['required', 'in:show_message,restart,shutdown'],
            'device_uuids' => ['sometimes', 'array', 'max:20'],
            'device_uuids.*' => ['required', 'uuid', 'distinct'],
            'all_online' => ['sometimes', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $action = $args['action'];
        $all = (bool) ($args['all_online'] ?? false);
        $uuids = $args['device_uuids'] ?? [];
        $max = max(1, min(20, (int) config('assistant.max_devices_per_action', 20)));
        if ($all && $uuids !== []) return ['result' => ['error' => 'ambito_contradictorio']];
        if (! $all && ($uuids === [] || count($uuids) > $max)) return ['result' => ['error' => 'destinatarios_no_validos']];
        $message = trim((string) ($args['message'] ?? ''));
        if ($action === 'show_message' && ($message === '' || mb_strlen($message) > 500)) {
            return ['result' => ['error' => 'mensaje_obligatorio']];
        }
        $query = $this->availability->online(Device::query()->whereNull('archived_at'), $this->availability->cutoff());
        if (! $all) $query->whereIn('uuid', $uuids);
        $devices = $query->orderBy('uuid')->limit($max + 1)->get(['uuid', 'name']);
        if ($devices->isEmpty() || $devices->count() > $max || (! $all && $devices->count() !== count($uuids))) {
            return ['result' => ['error' => 'equipos_desconectados_ambiguos_o_limite_superado', 'max_devices' => $max]];
        }
        $targetUuids = $devices->pluck('uuid')->all();
        $proposal = AiActionProposal::query()->create([
            'uuid' => (string) Str::uuid(), 'user_id' => $userId, 'batch_uuid' => (string) Str::uuid(),
            'action' => $action, 'device_uuids' => $targetUuids,
            'message' => $action === 'show_message' ? $message : null,
            'status' => 'pending', 'expires_at' => now()->addSeconds((int) config('assistant.proposal_ttl_seconds', 120)),
        ]);
        AuditLog::query()->create(['user_id' => $userId, 'event' => 'ai.action_prepared', 'context' => [
            'proposal_uuid' => $proposal->uuid, 'action' => $action, 'recipients' => count($targetUuids),
        ]]);
        $preview = [
            'uuid' => $proposal->uuid, 'action' => $action,
            'recipients' => $devices->map(fn (Device $d) => ['uuid' => $d->uuid, 'name' => $d->name])->all(),
            'message' => $proposal->message, 'expires_at' => $proposal->expires_at->toIso8601String(),
            'status' => 'pending',
        ];
        return ['result' => [
            'proposal_uuid' => $proposal->uuid, 'action' => $action,
            'recipients' => count($targetUuids), 'expires_at' => $preview['expires_at'],
            'status' => 'pendiente_de_confirmacion',
            'note' => 'No se ha enviado ninguna orden. Un administrador debe aprobar la propuesta en el panel.',
        ], 'proposal' => $preview];
    }

    private function strictCoreCount(mixed $value): ?int
    {
        if (is_int($value)) return $value > 0 && $value <= 2048 ? $value : null;
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2048]]);
        return $parsed === false ? null : $parsed;
    }

    private function check(array $args, array $rules): void
    {
        // Las herramientas del modelo son datos no confiables: prohibir argumentos desconocidos.
        if (array_diff(array_keys($args), array_map(static fn ($key) => explode('.', $key)[0], array_keys($rules)))) {
            throw ValidationException::withMessages(['arguments' => 'La herramienta recibió parámetros desconocidos.']);
        }
        validator($args, $rules)->validate();
    }
}
