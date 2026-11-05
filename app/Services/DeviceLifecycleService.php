<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** A revoked device never becomes authorized again through a simple UI toggle. */
final class DeviceLifecycleService
{
    public function revoke(Device $device, int $adminId): void
    {
        DB::transaction(function () use ($device, $adminId): void {
            if ($device->revoked_at) return;
            $device->update(['revoked_at' => now()]);
            AuditLog::query()->create(['user_id' => $adminId, 'device_id' => $device->id,
                'event' => 'device.revoked', 'context' => ['device_uuid' => $device->uuid]]);
        }, 3);
    }

    public function archive(Device $device, int $adminId): void
    {
        DB::transaction(function () use ($device, $adminId): void {
            if ($device->archived_at) return;
            // Archiving revokes access immediately, rather than leaving a hidden live agent.
            $device->update(['archived_at' => now(), 'revoked_at' => $device->revoked_at ?? now()]);
            AuditLog::query()->create(['user_id' => $adminId, 'device_id' => $device->id,
                'event' => 'device.archived', 'context' => ['device_uuid' => $device->uuid]]);
        }, 3);
    }

    public function restoreToInventory(Device $device, int $adminId): void
    {
        DB::transaction(function () use ($device, $adminId): void {
            if (! $device->archived_at) return;
            // Access is NOT restored: the agent must be explicitly re-enrolled.
            $device->update(['archived_at' => null]);
            AuditLog::query()->create(['user_id' => $adminId, 'device_id' => $device->id,
                'event' => 'device.restored', 'context' => ['device_uuid' => $device->uuid]]);
        }, 3);
    }

    /** Deletes the device graph, then its private PDF files; never touches Windows. */
    public function delete(Device $device, int $adminId): void
    {
        $paths = DB::transaction(function () use ($device, $adminId): array {
            $device = Device::query()->findOrFail($device->id);
            if (DeviceReport::query()->where('device_id', $device->id)
                ->whereIn('status', ['pending', 'processing'])->exists()) {
                throw ValidationException::withMessages(['device' =>
                    'Hay informes en preparación. Esperá a que finalicen antes de eliminar el equipo.']);
            }
            $reportFiles = DeviceReport::query()->where('device_id', $device->id)
                ->whereNotNull('file_path')->pluck('file_path')->all();
            AuditLog::query()->create(['user_id' => $adminId, 'device_id' => null,
                'event' => 'device.deleted',
                'context' => ['device_uuid' => $device->uuid, 'device_name' => $device->name]]);
            $device->delete(); // Existing FK cascades delete telemetry/inventory/events/reports.
            return $reportFiles;
        }, 3);

        $directory = realpath(storage_path('app/private/reports'));
        foreach ($paths as $path) {
            if (! is_string($path) || $directory === false) continue;
            $real = realpath($path);
            if ($real !== false && str_starts_with($real, $directory.DIRECTORY_SEPARATOR) && is_file($real) && ! @unlink($real)) {
                // A cleanup failure is reported but does not roll back already-deleted DB rows.
                Log::warning('Could not delete private report after device deletion', ['file_hash' => hash('sha256', $real)]);
            }
        }
    }
}
