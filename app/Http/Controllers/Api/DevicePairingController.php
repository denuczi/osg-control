<?php

namespace App\Http\Controllers\Api;

use App\Models\DevicePairingRequest;
use App\Services\DevicePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DevicePairingController
{
    public function create(Request $request, DevicePairingService $service): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 2048, 413);
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'hostname' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'max:120'],
            'token' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        return response()->json($service->request($data, $request->ip()), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, DevicePairingRequest $pairing, DevicePairingService $service): JsonResponse
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/^[a-fA-F0-9]{64}$/D', $token), 404);
        return response()->json($service->status($pairing, $token))
            ->header('Cache-Control', 'no-store');
    }
}
