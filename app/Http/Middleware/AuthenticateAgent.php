<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
            return response()->json(['message' => 'Credenciales del agente inválidas.'], 401);
        }
        $device = Device::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->whereNull('archived_at')->first();
        if (!$device || $device->is_demo) {
            return response()->json(['message' => 'Agente no autorizado.'], 401);
        }
        $request->attributes->set('agent_device', $device);
        return $next($request);
    }
}
