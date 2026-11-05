<?php

namespace App\Services;

use App\Models\BulkCommandBatch;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Única vía de creación de órdenes masivas, también para propuestas aprobadas de IA. */
final class BulkCommandService
{
    public function __construct(private readonly DeviceAvailability $availability) {}

    /** @param list<string> $deviceUuids */
    public function dispatch(int $actor, string $action, string $batchUuid, array $deviceUuids, bool $allOnline = false, string $message = ''): int
    {
        if (! in_array($action, ['show_message', 'restart', 'shutdown'], true)) {
            throw ValidationException::withMessages(['action' => 'La acción solicitada no está permitida.']);
        }
        $message = trim($message);
        if ($action === 'show_message' && ($message === '' || mb_strlen($message) > 500)) {
            throw ValidationException::withMessages(['message' => 'El mensaje debe tener entre 1 y 500 caracteres.']);
        }
        if (! $allOnline && ($deviceUuids === [] || count($deviceUuids) > 100 || count(array_unique($deviceUuids)) !== count($deviceUuids))) {
            throw ValidationException::withMessages(['device_uuids' => 'Seleccioná entre 1 y 100 equipos distintos.']);
        }
        $selected = $allOnline ? [] : array_values($deviceUuids);
        sort($selected, SORT_STRING);
        $parameters = $action === 'show_message' ? ['message' => $message] : [];
        $signature = hash('sha256', json_encode([
            'action' => $action, 'parameters' => $parameters,
            'all_online' => $allOnline, 'selected' => $selected,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($action, $batchUuid, $actor, $selected, $allOnline, $parameters, $signature): int {
            $inserted = DB::table('bulk_command_batches')->insertOrIgnore([
                'uuid' => $batchUuid, 'user_id' => $actor,
                'action' => $action, 'request_hash' => $signature,
                'target_uuids' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $batch = BulkCommandBatch::query()->findOrFail($batchUuid);
            if ($batch->user_id !== $actor || ! hash_equals($batch->request_hash, $signature)) {
                throw ValidationException::withMessages([
                    'batch_uuid' => 'Esta operación ya existe con otros parámetros o pertenece a otro administrador.',
                ]);
            }
            if ($inserted === 0) return count($batch->target_uuids ?? []);

            $query = $this->availability->online(Device::query()->whereNull('archived_at'), $this->availability->cutoff());
            if (! $allOnline) $query->whereIn('uuid', $selected);
            $devices = $query->orderBy('id')->limit(101)->get(['id', 'uuid']);
            if ($devices->isEmpty() || $devices->count() > 100 || (! $allOnline && $devices->count() !== count($selected))) {
                throw ValidationException::withMessages([
                    'device_uuids' => 'Todos los equipos seleccionados deben estar registrados y conectados (máximo 100).',
                ]);
            }
            $targets = $devices->pluck('uuid')->all();
            $batch->update(['target_uuids' => $targets]);
            $time = now();
            $commands = [];
            $audits = [];
            foreach ($devices as $device) {
                $uuid = (string) Str::uuid();
                $commands[] = [
                    'uuid' => $uuid, 'batch_uuid' => $batch->uuid,
                    'device_id' => $device->id, 'user_id' => $actor,
                    'action' => $action, 'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                    'status' => 'pending', 'expires_at' => $time->copy()->addMinutes($action === 'show_message' ? 10 : 2),
                    'created_at' => $time, 'updated_at' => $time,
                ];
                $audits[] = [
                    'user_id' => $actor, 'device_id' => $device->id, 'event' => 'command.created',
                    'context' => json_encode([
                        'command_uuid' => $uuid, 'action' => $action,
                        'bulk' => true, 'batch_uuid' => $batch->uuid,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $time, 'updated_at' => $time,
                ];
            }
            DB::table('device_commands')->insert($commands);
            DB::table('audit_logs')->insert($audits);
            return count($targets);
        }, 3);
    }
}
