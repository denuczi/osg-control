<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DevicePairingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Never logs or returns agent access tokens. Approval and device issuance are atomic. */
final class DevicePairingService
{
    public function request(array $input, ?string $sourceIp): array
    {
        return DB::transaction(function () use ($input, $sourceIp): array {
            if (Device::query()->where('uuid', $input['uuid'])->exists() ||
                DevicePairingRequest::query()->where('device_uuid', $input['uuid'])->exists()) {
                throw ValidationException::withMessages(['uuid' => 'Este identificador ya fue utilizado.']);
            }
            if (Device::query()->where('token_hash', hash('sha256', strtolower($input['token'])))->exists()) {
                throw ValidationException::withMessages(['request' => 'No se puede reutilizar una credencial existente.']);
            }
            if (DevicePairingRequest::query()->where('status', 'pending')
                ->where('expires_at', '>', now())->count() >= 100) {
                throw ValidationException::withMessages(['request' => 'Se alcanzó el límite de solicitudes pendientes.']);
            }
            if ($sourceIp !== null && DevicePairingRequest::query()
                ->where('source_ip', $sourceIp)->where('status', 'pending')
                ->where('expires_at', '>', now())->count() >= 30) {
                throw ValidationException::withMessages(['request' => 'Hay demasiadas solicitudes pendientes desde esta dirección.']);
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pairing = DevicePairingRequest::query()->create([
                'uuid' => (string) Str::uuid(), 'device_uuid' => $input['uuid'],
                'hostname' => $input['hostname'], 'platform' => $input['platform'],
                'source_ip' => $sourceIp, 'token_hash' => hash('sha256', strtolower($input['token'])),
                'verification_code_encrypted' => Crypt::encryptString($code),
                'status' => 'pending', 'expires_at' => now()->addMinutes(5),
            ]);
            return ['request_id' => $pairing->uuid, 'verification_code' => $code,
                'expires_at' => $pairing->expires_at->toIso8601String()];
        }, 3);
    }

    /** Idempotent: the token remains exclusively on the agent until approval. */
    public function status(DevicePairingRequest $pairing, string $token): array
    {
        abort_unless(hash_equals($pairing->token_hash, hash('sha256', strtolower($token))), 404);
        if ($pairing->expires_at->isPast() && $pairing->status === 'pending') {
            return ['status' => 'expired'];
        }
        return ['status' => $pairing->status, 'device_id' => $pairing->status === 'approved' ? $pairing->device_uuid : null];
    }

    public function approve(DevicePairingRequest $pairing, string $code, int $adminId): void
    {
        DB::transaction(function () use ($pairing, $code, $adminId): void {
            $record = DevicePairingRequest::query()->findOrFail($pairing->id);
            if ($record->status !== 'pending' || $record->expires_at->isPast()) {
                throw ValidationException::withMessages(['verification_code' => 'La solicitud ya fue resuelta o venció.']);
            }
            if ($record->failed_attempts >= 3) {
                throw ValidationException::withMessages(['verification_code' => 'Solicitud bloqueada por intentos incorrectos.']);
            }
            $valid = hash_equals(Crypt::decryptString($record->verification_code_encrypted), $code);
            if (! $valid) {
                $record->increment('failed_attempts');
                // In a failed check the transaction must still commit the attempt counter.
                return;
            }
            if (Device::query()->where('uuid', $record->device_uuid)->exists()) {
                throw ValidationException::withMessages(['verification_code' => 'Ya existe un equipo con este identificador.']);
            }
            if (Device::query()->where('hostname', $record->hostname)
                ->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['verification_code' =>
                    'Ya existe un equipo no archivado con ese nombre. Revisá su registro y archívalo antes de autorizar una vinculación nueva.']);
            }
            // Claim before creating the device: a second approval cannot race the same request.
            $claimed = DevicePairingRequest::query()->whereKey($record->id)
                ->where('status', 'pending')->where('expires_at', '>', now())
                ->update(['status' => 'approved', 'approved_by' => $adminId, 'resolved_at' => now()]);
            if ($claimed !== 1) {
                throw ValidationException::withMessages(['verification_code' => 'La solicitud ya fue resuelta.']);
            }
            $device = Device::query()->create([
                'uuid' => $record->device_uuid, 'name' => $record->hostname,
                'hostname' => $record->hostname, 'platform' => $record->platform,
                'token_hash' => $record->token_hash,
            ]);
            AuditLog::query()->create(['user_id' => $adminId, 'device_id' => $device->id,
                'event' => 'device.pairing.approved', 'context' => ['hostname' => $device->hostname]]);
        }, 3);
        $pairing->refresh();
        if ($pairing->status !== 'approved') {
            throw ValidationException::withMessages(['verification_code' =>
                $pairing->failed_attempts >= 3 ? 'Solicitud bloqueada. Iniciá otra vinculación.' : 'El código de verificación no coincide.']);
        }
    }

    public function reject(DevicePairingRequest $pairing, int $adminId): void
    {
        DB::transaction(function () use ($pairing, $adminId): void {
            $claimed = DevicePairingRequest::query()->whereKey($pairing->id)
                ->where('status', 'pending')->update(['status' => 'rejected', 'resolved_at' => now()]);
            if ($claimed !== 1) {
                throw ValidationException::withMessages(['pairing' => 'La solicitud ya fue resuelta.']);
            }
            AuditLog::query()->create(['user_id' => $adminId, 'event' => 'device.pairing.rejected',
                'context' => ['hostname' => $pairing->hostname]]);
        }, 3);
    }
}
