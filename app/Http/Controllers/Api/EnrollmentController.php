<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Models\EnrollmentCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:48', 'regex:/^[a-f0-9]+$/i'],
            'uuid' => ['required', 'uuid'],
            'hostname' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'max:120'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $codeHash = hash('sha256', strtolower($data['code']));
            $enrollment = EnrollmentCode::query()->where('code_hash', $codeHash)
                ->whereNull('used_at')->where('expires_at', '>', now())->first();
            if (!$enrollment) { return null; }
            if (Device::query()->where('uuid', $data['uuid'])->exists()) { return 'already_registered'; }

            // Solo un cliente puede consumir este código, aunque dos intenten utilizarlo a la vez.
            $claimed = EnrollmentCode::query()->whereKey($enrollment->id)->whereNull('used_at')
                ->where('expires_at', '>', now())->update(['used_at' => now()]);
            if ($claimed !== 1) { return null; }

            $token = bin2hex(random_bytes(32));
            $device = Device::query()->create([
                'uuid' => $data['uuid'],
                'name' => $data['hostname'],
                'hostname' => $data['hostname'],
                'platform' => $data['platform'],
                'token_hash' => hash('sha256', $token),
            ]);
            return ['token' => $token, 'device' => $device];
        });

        if ($result === 'already_registered') {
            return response()->json(['message' => 'Este UUID ya está registrado. Contactá al administrador.'], 409);
        }
        if (!$result) { return response()->json(['message' => 'Código inválido, vencido o ya utilizado.'], 422); }

        return response()->json([
            'device_id' => $result['device']->uuid,
            'token' => $result['token'],
            'message' => 'Equipo registrado correctamente.',
        ], 201);
    }
}
