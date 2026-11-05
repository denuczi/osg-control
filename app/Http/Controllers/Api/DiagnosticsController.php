<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Models\DeviceDiagnostic;
use App\Models\DeviceSystemEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DiagnosticsController
{
    public function snapshot(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 49152) {
            return response()->json(['message' => 'Diagnóstico demasiado grande.'], 413);
        }
        $data = $request->validate([
            'captured_at' => ['required', 'date'],
            'hardware' => ['required', 'array:status,data'],
            'disks' => ['required', 'array:status,data'],
            'security' => ['required', 'array:status,data'],
            ...$this->sectionRules('hardware'),
            ...$this->sectionRules('disks'),
            ...$this->sectionRules('security'),
            'hardware.data' => ['present', 'nullable', 'array:Manufacturer,Model,BiosVersion,Serial,Board,MemorySlots,MemoryModules,BatteryPresent,BatteryPercent,BatteryStatus,BatteryDesignCapacity,BatteryFullChargeCapacity,BatteryCycles'],
            'hardware.data.MemoryModules.*' => ['array:CapacityBytes,SpeedMHz,Manufacturer,PartNumber,Slot'],
            'hardware.data.Manufacturer' => ['nullable', 'string', 'max:160'],
            'hardware.data.Model' => ['nullable', 'string', 'max:160'],
            'hardware.data.BiosVersion' => ['nullable', 'string', 'max:160'],
            'hardware.data.Serial' => ['nullable', 'string', 'max:160'],
            'hardware.data.Board' => ['nullable', 'string', 'max:160'],
            'hardware.data.MemorySlots' => ['nullable', 'integer', 'between:0,128'],
            'hardware.data.MemoryModules' => ['nullable', 'array', 'max:64'],
            'hardware.data.MemoryModules.*.CapacityBytes' => ['nullable', 'integer', 'min:0'],
            'hardware.data.MemoryModules.*.SpeedMHz' => ['nullable', 'integer', 'between:0,20000'],
            'hardware.data.MemoryModules.*.Manufacturer' => ['nullable', 'string', 'max:100'],
            'hardware.data.MemoryModules.*.PartNumber' => ['nullable', 'string', 'max:120'],
            'hardware.data.MemoryModules.*.Slot' => ['nullable', 'string', 'max:100'],
            'hardware.data.BatteryPresent' => ['nullable', 'boolean'],
            'hardware.data.BatteryPercent' => ['nullable', 'integer', 'between:0,100'],
            'hardware.data.BatteryStatus' => ['nullable', 'string', 'max:20'],
            'hardware.data.BatteryDesignCapacity' => ['nullable', 'integer', 'min:0'],
            'hardware.data.BatteryFullChargeCapacity' => ['nullable', 'integer', 'min:0'],
            'hardware.data.BatteryCycles' => ['nullable', 'integer', 'min:0'],
            'disks.data' => ['present', 'nullable', 'array:drives'],
            'disks.data.drives.*' => ['array:Name,DeviceId,MediaType,Health,Temperature,Wear,PowerOnHours,ReadErrors,WriteErrors'],
            'disks.data.drives' => ['nullable', 'array', 'max:24'],
            'disks.data.drives.*.Name' => ['nullable', 'string', 'max:160'],
            'disks.data.drives.*.DeviceId' => ['nullable', 'string', 'max:40'],
            'disks.data.drives.*.MediaType' => ['nullable', 'string', 'max:30'],
            'disks.data.drives.*.Health' => ['nullable', 'string', 'max:30'],
            'disks.data.drives.*.Temperature' => ['nullable', 'integer', 'between:0,150'],
            'disks.data.drives.*.Wear' => ['nullable', 'integer', 'between:0,100'],
            'disks.data.drives.*.PowerOnHours' => ['nullable', 'integer', 'min:0'],
            'disks.data.drives.*.ReadErrors' => ['nullable', 'integer', 'min:0'],
            'disks.data.drives.*.WriteErrors' => ['nullable', 'integer', 'min:0'],
            'security.data' => ['present', 'nullable', 'array:DefenderMode,DefenderRealtime,DefenderSignatureAge,DefenderSignatureUpdated,AntivirusProviders,FirewallProfiles,WindowsUpdatesPending,ProtectionQueryStatus,Windows'],
            'security.data.Windows' => ['sometimes', 'nullable', 'array:Caption,EditionId,DisplayVersion,Build,UBR,EsuCommercialStatus,EsuLicensedYears'],
            'security.data.Windows.Caption' => ['nullable', 'string', 'max:160'],
            'security.data.Windows.EditionId' => ['nullable', 'string', 'max:80'],
            'security.data.Windows.DisplayVersion' => ['nullable', 'string', 'max:32'],
            'security.data.Windows.Build' => ['nullable', 'integer', 'between:0,999999'],
            'security.data.Windows.UBR' => ['nullable', 'integer', 'between:0,999999'],
            'security.data.Windows.EsuCommercialStatus' => ['sometimes', Rule::in(['licensed', 'not_detected', 'unknown', 'not_applicable'])],
            'security.data.Windows.EsuLicensedYears' => ['sometimes', 'array', 'max:3'],
            'security.data.Windows.EsuLicensedYears.*' => ['integer', Rule::in([1, 2, 3]), 'distinct'],
            'security.data.FirewallProfiles.*' => ['array:Name,Enabled'],
            'security.data.DefenderMode' => ['nullable', 'string', 'max:60'],
            'security.data.DefenderRealtime' => ['nullable', 'boolean'],
            'security.data.WindowsUpdatesPending' => ['nullable', 'integer', 'between:0,100000'],
            'security.data.ProtectionQueryStatus' => ['sometimes', Rule::in(['ok', 'unavailable', 'unknown'])],
            'security.data.DefenderSignatureAge' => ['nullable', 'integer', 'min:0'],
            'security.data.DefenderSignatureUpdated' => ['nullable', 'date'],
            'security.data.AntivirusProviders' => ['nullable', 'array', 'max:12'],
            'security.data.AntivirusProviders.*' => ['string', 'max:160'],
            'security.data.FirewallProfiles' => ['nullable', 'array', 'max:8'],
            'security.data.FirewallProfiles.*.Name' => ['required', 'string', 'max:30'],
            'security.data.FirewallProfiles.*.Enabled' => ['required', 'boolean'],
        ]);
        $at = Carbon::parse($data['captured_at'])->utc();
        if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subDays(30))) {
            return response()->json(['message' => 'Fecha de diagnóstico fuera de rango.',
                'errors' => ['captured_at' => ['Fuera del intervalo aceptado.']]], 422);
        }
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        DB::transaction(function () use ($device, $at, $data): void {
            $current = DeviceDiagnostic::query()->where('device_id', $device->id)->first();
            if ($current && !$at->gt($current->captured_at)) {
                return;
            }
            DeviceDiagnostic::query()->updateOrCreate(['device_id' => $device->id], [
                'hardware' => $data['hardware'], 'disks' => $data['disks'],
                'security' => $data['security'], 'captured_at' => $at, 'received_at' => now(),
            ]);
        }, 3);
        return response()->json(['ok' => true, 'captured_at' => $data['captured_at']]);
    }

    public function events(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 49152) {
            return response()->json(['message' => 'Lote de eventos demasiado grande.'], 413);
        }
        $allowed = ['System' => [41, 6008, 1074, 13, 6006, 7, 51, 55, 129, 153, 7045],
                    'Application' => [1000, 1002]];
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:30'],
            'events.*' => ['required', 'array:event_uuid,channel,record_id,event_id,provider,level,observed_at,message,user_sid'],
            'events.*.event_uuid' => ['required', 'uuid', 'distinct'],
            'events.*.channel' => ['required', Rule::in(array_keys($allowed))],
            'events.*.record_id' => ['required', 'integer', 'min:1'],
            'events.*.event_id' => ['required', 'integer', Rule::in(array_merge(...array_values($allowed)))],
            'events.*.provider' => ['required', 'string', 'max:120'],
            'events.*.level' => ['nullable', 'integer', 'between:0,5'],
            'events.*.observed_at' => ['required', 'date'],
            'events.*.message' => ['nullable', 'string', 'max:1200'],
            'events.*.user_sid' => ['nullable', 'string', 'max:120', 'regex:/^S-[0-9-]+$/'],
        ]);
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        $rows = [];
        foreach ($data['events'] as $event) {
            if (!in_array($event['event_id'], $allowed[$event['channel']], true)) {
                return response()->json(['message' => 'Evento fuera del catálogo.'], 422);
            }
            $at = Carbon::parse($event['observed_at'])->utc();
            if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subDays(180))) {
                return response()->json(['message' => 'Fecha de evento fuera de rango.'], 422);
            }
            $rows[] = [
                'device_id' => $device->id, 'event_uuid' => $event['event_uuid'],
                'channel' => $event['channel'], 'record_id' => $event['record_id'],
                'event_id' => $event['event_id'], 'provider' => $event['provider'],
                'level' => $event['level'] ?? null, 'observed_at' => $at,
                'message' => $event['message'] ?? null, 'user_sid' => $event['user_sid'] ?? null,
                'received_at' => now(),
            ];
        }
        DB::transaction(function () use ($device, $rows): void {
            $existing = DeviceSystemEvent::query()->where('device_id', $device->id)
                ->whereIn('event_uuid', array_column($rows, 'event_uuid'))->get()->keyBy('event_uuid');
            foreach ($rows as $row) {
                $previous = $existing->get($row['event_uuid']);
                if ($previous && (
                    $previous->channel !== $row['channel'] ||
                    $previous->record_id !== $row['record_id'] ||
                    $previous->event_id !== $row['event_id'] ||
                    $previous->provider !== $row['provider'] ||
                    $previous->level !== $row['level'] ||
                    !$previous->observed_at->equalTo($row['observed_at'])
                )) {
                    throw ValidationException::withMessages([
                        'events' => 'El identificador de un evento ya existe con otros datos.',
                    ]);
                }
            }
            DeviceSystemEvent::query()->insertOrIgnore($rows);
            foreach ($rows as $row) {
                if ($row['message'] === null && $row['user_sid'] === null) continue;
                // Preserve first nonempty details; no replacement on retransmission.
                DeviceSystemEvent::query()->where('device_id', $device->id)
                    ->where('event_uuid', $row['event_uuid'])
                    ->whereNull('message')->update([
                        'message' => $row['message'], 'user_sid' => $row['user_sid'],
                    ]);
            }
        }, 3);
        return response()->json(['ok' => true, 'accepted_ids' => array_column($rows, 'event_uuid')]);
    }

    private function sectionRules(string $name): array
    {
        return [
            "$name.status" => ['required', Rule::in(['ok', 'unavailable', 'unsupported', 'error'])],
            "$name.data" => ['present', 'nullable', 'array'],
        ];
    }
}
