<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TelemetryQuotaExceeded;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\MetricSample;
use App\Models\DeviceInventory;
use App\Models\DeviceMediaEvent;
use App\Services\TelemetryCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\MonitorSettings;
use Illuminate\Support\Carbon;

class AgentController
{
    private function agentResponse(array $data = []): JsonResponse
    {
        $settings = app(MonitorSettings::class)->agent();
        return response()->json(array_merge([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'settings' => $settings,
            'next_poll_seconds' => $settings['command_poll_seconds'],
        ], $data));
    }

    public function inventory(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 131072) {
            return response()->json(['message' => 'Inventario demasiado grande.'], 413);
        }
        $data = $request->validate([
            'captured_at' => ['required', 'date'],
            'processor' => ['required', 'array'],
            'processor.name' => ['nullable', 'string', 'max:160'],
            'processor.physical_cores' => ['nullable', 'integer', 'between:1,1024'],
            'processor.logical_processors' => ['nullable', 'integer', 'between:1,2048'],
            'processor.max_mhz' => ['nullable', 'numeric', 'between:1,20000'],
            'processor.current_mhz' => ['nullable', 'numeric', 'between:1,20000'],
            'volumes' => ['present', 'array', 'max:32'],
            'volumes.*.mountpoint' => ['required', 'string', 'max:120'],
            'volumes.*.device' => ['required', 'string', 'max:160'],
            'volumes.*.filesystem' => ['nullable', 'string', 'max:40'],
            'volumes.*.label' => ['nullable', 'string', 'max:100'],
            'volumes.*.total_bytes' => ['required', 'integer', 'min:0'],
            'volumes.*.used_bytes' => ['required', 'integer', 'min:0'],
            'volumes.*.free_bytes' => ['required', 'integer', 'min:0'],
            'volumes.*.used_percent' => ['required', 'numeric', 'between:0,100'],
            'volumes.*.removable' => ['required', 'boolean'],
            'volumes.*.media_type' => ['nullable', 'in:SSD,HDD,SCM'],
            'volumes.*.bus_type' => ['nullable', 'string', 'max:30'],
            'volumes.*.model' => ['nullable', 'string', 'max:120'],
            'network' => ['required', 'array'],
            'network.default_gateway' => ['nullable', 'ip'],
            'network.dns_servers' => ['present', 'array', 'max:12'],
            'network.dns_servers.*' => ['ip'],
            'network.ssid' => ['nullable', 'string', 'max:100'],
            'network.interfaces' => ['present', 'array', 'max:32'],
            'network.interfaces.*.name' => ['required', 'string', 'max:120'],
            'network.interfaces.*.up' => ['required', 'boolean'],
            'network.interfaces.*.link_speed_mbps' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'network.interfaces.*.mac' => ['nullable', 'string', 'max:32'],
            'network.interfaces.*.ipv4' => ['present', 'array', 'max:8'],
            'network.interfaces.*.ipv4.*.address' => ['required', 'ipv4'],
            'network.interfaces.*.ipv4.*.netmask' => ['nullable', 'ipv4'],
            'network.interfaces.*.ipv4.*.subnet' => ['nullable', 'string', 'max:43'],
            'network.profiles' => ['sometimes', 'array', 'max:16'],
            'network.profiles.*.interface' => ['required', 'string', 'max:120'],
            'network.profiles.*.name' => ['required', 'string', 'max:100'],
            'network.profiles.*.category' => ['nullable', 'string', 'max:30'],
            'network.profiles.*.ipv4_connectivity' => ['nullable', 'string', 'max:40'],
            'network.neighbors' => ['sometimes', 'array', 'max:64'],
            'network.neighbors.*.ip' => ['required', 'ip'],
            'network.neighbors.*.mac' => ['nullable', 'string', 'max:32'],
            'network.neighbors.*.state' => ['nullable', 'string', 'max:30'],
            'network.interfaces.*.ipv6' => ['present', 'array', 'max:8'],
            'network.interfaces.*.ipv6.*' => ['ipv6'],
        ]);
        $at = Carbon::parse($data['captured_at'])->utc();
        if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subDays(30))) {
            return response()->json(['message' => 'Fecha de inventario no válida.', 'errors' => ['captured_at' => ['Fuera del intervalo aceptado.']]], 422);
        }
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        DB::transaction(function () use ($device, $data, $at): void {
            $existing = DeviceInventory::query()->where('device_id', $device->id)->first();
            if ($existing && !$at->gt($existing->captured_at)) {
                return; // Retransmisión u observación anterior: no revertir inventario.
            }
            DeviceInventory::query()->updateOrCreate(['device_id' => $device->id], [
                'processor' => $data['processor'], 'volumes' => $data['volumes'],
                'network' => $data['network'], 'captured_at' => $at,
            ]);
        });
        return $this->agentResponse();
    }

    public function mediaEventsBatch(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 131072) {
            return response()->json(['message' => 'Lote demasiado grande.'], 413);
        }
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.event_uuid' => ['required', 'uuid', 'distinct'],
            'events.*.observed_at' => ['required', 'date'],
            'events.*.kind' => ['required', 'in:connected,disconnected,detected'],
            'events.*.volume' => ['required', 'array'],
            'events.*.volume.mountpoint' => ['required', 'string', 'max:120'],
            'events.*.volume.device' => ['required', 'string', 'max:160'],
            'events.*.volume.total_bytes' => ['required', 'integer', 'min:0'],
            'events.*.volume.used_bytes' => ['required', 'integer', 'min:0'],
            'events.*.volume.free_bytes' => ['required', 'integer', 'min:0'],
            'events.*.volume.removable' => ['required', 'boolean'],
            'events.*.volume.media_class' => ['nullable', 'in:usb,optical,removable'],
            'events.*.volume.label' => ['nullable', 'string', 'max:100'],
            'events.*.volume.filesystem' => ['nullable', 'string', 'max:40'],
            'events.*.volume.serial' => ['nullable', 'string', 'max:100'],
        ]);
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        $rows = [];
        $now = now();
        foreach ($data['events'] as $event) {
            $at = Carbon::parse($event['observed_at'])->utc();
            if ($at->lt($now->copy()->subDays(max(1, min(3650, (int)config('nodo.media_retention_days', 180))))) || $at->gt($now->copy()->addMinutes(5))) {
                return response()->json(['message' => 'Fecha de evento inválida.', 'errors' => ['events.*.observed_at' => ['Fuera del intervalo aceptado.']]], 422);
            }
            $rows[] = [
                'device_id' => $device->id, 'event_uuid' => $event['event_uuid'],
                'observed_at' => $at, 'received_at' => $now, 'kind' => $event['kind'],
                'volume' => json_encode($event['volume'], JSON_THROW_ON_ERROR),
            ];
        }
        DeviceMediaEvent::query()->insertOrIgnore($rows);
        return $this->agentResponse(['accepted_ids' => array_column($data['events'], 'event_uuid')]);
    }

    /**
     * Windows psutil can report System Idle Process (PID 0). Accept it in
     * historic offline batches, but never publish it as a controllable process.
     * PID < 0 and all other malformed entries remain validation errors.
     */
    private function filterReservedProcessIds(array $processes): array
    {
        return array_values(array_filter(
            $processes,
            static fn (array $process): bool => $process['pid'] > 0,
        ));
    }

    private function extraMetricRules(string $prefix = ''): array
    {
        $rules = [];
        foreach (['memory_used_bytes','memory_available_bytes','disk_total_bytes','disk_used_bytes','disk_free_bytes','network_rx_bps','network_tx_bps'] as $field) {
            $rules[$prefix.$field] = ['sometimes', 'nullable', 'integer', 'min:0'];
        }
        return $rules;
    }

    public function heartbeat(Request $request): JsonResponse
    {
        // New agents send a lightweight heartbeat; older agents may still
        // include a complete sample. Keeping both forms avoids breaking upgrades.
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'max:120'],
            'cpu_percent' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'memory_percent' => ['required_with:cpu_percent', 'numeric', 'between:0,100'],
            'disk_percent' => ['required_with:cpu_percent', 'numeric', 'between:0,100'],
            'memory_total_bytes' => ['required_with:cpu_percent', 'integer', 'min:0'],
            ...$this->extraMetricRules(),
            'processes' => ['required_with:cpu_percent', 'array', 'max:250'],
            'processes_total_count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'processes.*.pid' => ['required', 'integer', 'min:0'],
            'processes.*.name' => ['required', 'string', 'max:160'],
            'processes.*.memory_mb' => ['required', 'numeric', 'min:0'],
            'processes.*.started_at' => ['required', 'numeric', 'min:0'],
            'processes.*.can_terminate' => ['required', 'boolean'],
            'processes.*.display_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'processes.*.group_key' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
        ]);
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        try {
            DB::transaction(function () use ($device, $data): void {
            $now = now();
            $record = isset($data['cpu_percent']) &&
                (!$device->last_sampled_at || $device->last_sampled_at->lte($now->copy()->subSeconds(15)));
            if ($record && !app(TelemetryCapacity::class)->prepare(1, $device->id)) {
                throw new TelemetryQuotaExceeded();
            }
            $fields = ['hostname' => $data['hostname'], 'platform' => $data['platform'], 'last_seen_at' => $now];
            if (isset($data['cpu_percent'])) {
                $fields += [
                    'cpu_percent' => $data['cpu_percent'], 'memory_percent' => $data['memory_percent'],
                    'disk_percent' => $data['disk_percent'], 'memory_total_bytes' => $data['memory_total_bytes'],
                    'processes' => $this->filterReservedProcessIds($data['processes']),
                    'processes_sampled_at' => $now,
                    'processes_total_count' => $data['processes_total_count'] ?? null,
                    ...$this->metricExtras($data), 'last_sampled_at' => $record ? $now : $device->last_sampled_at,
                ];
                if ($record) {
                    MetricSample::query()->create([
                        'device_id' => $device->id, 'cpu_percent' => $data['cpu_percent'],
                        'memory_percent' => $data['memory_percent'], 'disk_percent' => $data['disk_percent'],
                        'recorded_at' => $now, 'received_at' => $now,
                        'network_rx_bps' => $data['network_rx_bps'] ?? null, 'network_tx_bps' => $data['network_tx_bps'] ?? null,
                    ]);
                }
            }
            $device->update($fields);
            });
        } catch (TelemetryQuotaExceeded) {
            return response()->json(['message' => 'Límite de telemetría alcanzado.'], 507);
        }
        return $this->agentResponse();
    }

    public function metricsBatch(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 262144) {
            return response()->json(['message' => 'Lote demasiado grande.'], 413);
        }
        $data = $request->validate([
            'samples' => ['required', 'array', 'min:1', 'max:100'],
            'samples.*.sample_uuid' => ['required', 'uuid', 'distinct'],
            'samples.*.captured_at' => ['required', 'date'],
            'samples.*.cpu_percent' => ['required', 'numeric', 'between:0,100'],
            'samples.*.memory_percent' => ['required', 'numeric', 'between:0,100'],
            'samples.*.disk_percent' => ['required', 'numeric', 'between:0,100'],
            'samples.*.memory_total_bytes' => ['required', 'integer', 'min:0'],
            ...$this->extraMetricRules('samples.*.'),
            'samples.*.processes' => ['sometimes', 'array', 'max:250'],
            'samples.*.processes_total_count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'samples.*.processes.*.pid' => ['required', 'integer', 'min:0'],
            'samples.*.processes.*.name' => ['required', 'string', 'max:160'],
            'samples.*.processes.*.memory_mb' => ['required', 'numeric', 'min:0'],
            'samples.*.processes.*.started_at' => ['required', 'numeric', 'min:0'],
            'samples.*.processes.*.can_terminate' => ['required', 'boolean'],
            'samples.*.processes.*.display_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'samples.*.processes.*.group_key' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
        ]);
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        $now = now();
        $rows = [];
        $latest = null;
        $latestAt = null;
        $latestProcesses = null;
        $latestProcessesAt = null;
        $latestProcessTotal = null;
        foreach ($data['samples'] as $sample) {
            $at = Carbon::parse($sample['captured_at'])->utc();
            if ($at->lt($now->copy()->subDays(30)) || $at->gt($now->copy()->addMinutes(5))) {
                return response()->json(['message' => 'Marca temporal fuera del intervalo permitido.', 'errors' => ['samples.*.captured_at' => ['Fuera del intervalo aceptado.']]], 422);
            }
            $rows[] = [
                'device_id' => $device->id, 'sample_uuid' => $sample['sample_uuid'],
                'recorded_at' => $at, 'received_at' => $now,
                'cpu_percent' => $sample['cpu_percent'], 'memory_percent' => $sample['memory_percent'],
                'disk_percent' => $sample['disk_percent'],
                'network_rx_bps' => $sample['network_rx_bps'] ?? null,
                'network_tx_bps' => $sample['network_tx_bps'] ?? null,
            ];
            if ($latestAt === null || $at->gt($latestAt)) {
                $latest = $sample;
                $latestAt = $at;
            }
            // The newest lightweight metric often has no process inventory.
            // Track the newest actual process capture independently.
            if (array_key_exists('processes', $sample) &&
                ($latestProcessesAt === null || $at->gt($latestProcessesAt))) {
                $latestProcesses = $this->filterReservedProcessIds($sample['processes']);
                $latestProcessTotal = $sample['processes_total_count'] ?? null;
                $latestProcessesAt = $at;
            }
        }
        $existing = MetricSample::query()->where('device_id', $device->id)
            ->whereIn('sample_uuid', array_column($rows, 'sample_uuid'))
            ->pluck('sample_uuid')->all();
        $newCount = count($rows) - count($existing);
        try {
            DB::transaction(function () use ($device, $rows, $latest, $latestAt, $latestProcesses, $latestProcessesAt, $latestProcessTotal, $now, $newCount): void {
            if ($newCount > 0 && !app(TelemetryCapacity::class)->prepare($newCount, $device->id)) {
                throw new TelemetryQuotaExceeded();
            }
            MetricSample::query()->insertOrIgnore($rows);
            // A replay from an offline agent must never overwrite newer data.
            $currentAt = Device::query()->whereKey($device->id)->value('last_sampled_at');
            if (!$currentAt || $latestAt->gt(Carbon::parse($currentAt))) {
                Device::query()->whereKey($device->id)->update([
                    'cpu_percent' => $latest['cpu_percent'],
                    'memory_percent' => $latest['memory_percent'],
                    'disk_percent' => $latest['disk_percent'],
                    'memory_total_bytes' => $latest['memory_total_bytes'],
                    ...$this->metricExtras($latest),
                    'last_sampled_at' => $latestAt,
                    'last_seen_at' => $now,
                ]);
            }
            // Process observations have their own timestamp. Replays must not
            // overwrite a newer process list even when they include newer CPU data.
            if ($latestProcessesAt !== null) {
                $processesAt = Device::query()->whereKey($device->id)->value('processes_sampled_at');
                if ($processesAt === null || $latestProcessesAt->gt(Carbon::parse($processesAt))) {
                    Device::query()->whereKey($device->id)->update([
                        'processes' => json_encode($latestProcesses, JSON_THROW_ON_ERROR),
                        'processes_sampled_at' => $latestProcessesAt,
                        'processes_total_count' => $latestProcessTotal,
                    ]);
                }
            }
            });
        } catch (TelemetryQuotaExceeded) {
            return response()->json(['message' => 'Límite de telemetría alcanzado; se conserva el lote en el agente.'], 507);
        }
        // Individual IDs are acknowledged even when they existed already.
        // The unique index makes retransmission safe after a lost HTTP response.
        return $this->agentResponse(['accepted_ids' => array_column($data['samples'], 'sample_uuid')]);
    }

    private function metricExtras(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'memory_used_bytes','memory_available_bytes','disk_total_bytes','disk_used_bytes',
            'disk_free_bytes','network_rx_bps','network_tx_bps',
        ]));
    }

    public function nextCommand(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        $command = DB::transaction(function () use ($device) {
            $now = now();
            // Expired dispatched orders may have executed: never pretend 'failed'
            // or execute them again. Mark outcome unknown for operator review.
            $stale = DeviceCommand::query()->where('device_id', $device->id)
                ->where('status', 'dispatched')->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)->limit(25)->get();
            foreach ($stale as $old) {
                $changed = DeviceCommand::query()->whereKey($old->id)->where('status', 'dispatched')
                    ->update(['status' => 'unknown', 'result_message' => 'Sin confirmación del agente antes de la expiración.', 'finished_at' => $now]);
                if ($changed) {
                    AuditLog::query()->create([
                        'device_id' => $device->id, 'user_id' => $old->user_id,
                        'event' => 'command.unknown',
                        'context' => ['command_uuid' => $old->uuid, 'action' => $old->action],
                    ]);
                }
            }
            $expired = DeviceCommand::query()->where('device_id', $device->id)->where('status', 'pending')
                ->whereNotNull('expires_at')->where('expires_at', '<=', $now)->limit(25)->get();
            foreach ($expired as $old) {
                $changed = DeviceCommand::query()->whereKey($old->id)->where('status', 'pending')
                    ->update(['status' => 'expired', 'finished_at' => $now]);
                if ($changed) {
                    AuditLog::query()->create([
                        'device_id' => $device->id, 'user_id' => $old->user_id,
                        'event' => 'command.expired',
                        'context' => ['command_uuid' => $old->uuid, 'action' => $old->action],
                    ]);
                }
            }
            // Prioritise an unacknowledged delivery. The local command receipt
            // makes retries of this *same UUID* safe, including shutdown/restart.
            $leaseCutoff = $now->copy()->subSeconds(30);
            $retry = DeviceCommand::query()->where('device_id', $device->id)
                ->where('status', 'dispatched')->where('dispatched_at', '<=', $leaseCutoff)
                ->where(function ($query) use ($now) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                })->orderBy('id')->first();
            if ($retry) {
                $updated = DeviceCommand::query()->whereKey($retry->id)->where('status', 'dispatched')
                    ->where('dispatched_at', '<=', $leaseCutoff)->update(['dispatched_at' => $now]);
                if ($updated) { return $retry->refresh(); }
            }
            $next = DeviceCommand::query()->where('device_id', $device->id)
                ->where('status', 'pending')->where(function ($query) use ($now) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                })->orderBy('id')->lockForUpdate()->first();
            if (!$next) { return null; }
            $reserved = DeviceCommand::query()->whereKey($next->id)->where('status', 'pending')
                ->update(['status' => 'dispatched', 'dispatched_at' => $now]);
            return $reserved === 1 ? $next->refresh() : null;
        });
        if (!$command) { return $this->agentResponse(['command' => null]); }
        return $this->agentResponse(['command' => [
            'id' => $command->uuid,
            'action' => $command->action,
            'parameters' => $command->parameters ?? new \stdClass(),
        ]]);
    }

    public function commandResult(Request $request, DeviceCommand $command): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        abort_unless($command->device_id === $device->id, 404);
        $data = $request->validate([
            'status' => ['required', 'in:completed,failed,unknown'],
            'message' => ['required', 'string', 'max:500'],
        ]);
        $changed = DB::transaction(function () use ($command, $device, $data): int {
            $updated = DeviceCommand::query()->whereKey($command->id)
                ->where(function ($query) {
                    $query->where('status', 'dispatched')
                        ->orWhere(function ($uncertain) {
                            $uncertain->where('status', 'unknown')
                                ->where('result_message', 'Sin confirmación del agente antes de la expiración.');
                        });
                })
                ->update([
                    'status' => $data['status'],
                    'result_message' => $data['message'],
                    'finished_at' => now(),
                ]);

            if ($updated === 1) {
                AuditLog::query()->create([
                    'device_id' => $device->id,
                    'user_id' => $command->user_id,
                    'event' => 'command.'.$data['status'],
                    'context' => [
                        'command_uuid' => $command->uuid,
                        'action' => $command->action,
                        'message' => $data['message'],
                    ],
                ]);
            }

            return $updated;
        });
        if (!$changed && !in_array($command->fresh()->status, ['completed', 'failed', 'unknown'], true)) {
            return response()->json(['message' => 'Estado de orden no válido.'], 409);
        }
        return $this->agentResponse();
    }
}
