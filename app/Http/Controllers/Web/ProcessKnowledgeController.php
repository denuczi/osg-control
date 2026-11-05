<?php

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Services\ProcessKnowledge;
use App\Support\DeviceViewData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** A cost-bounded, administrator-only informational endpoint. No command execution. */
final class ProcessKnowledgeController
{
    public function __invoke(Request $request, Device $device, ProcessKnowledge $knowledge): JsonResponse
    {
        abort_if($device->revoked_at !== null || $device->archived_at !== null, 404);
        $data = $request->validate([
            'process' => ['required', 'string', 'max:160', 'regex:/\A[\p{L}\p{N}_][\p{L}\p{N}_.()+ ~-]*\z/u'],
        ]);
        $requested = mb_strtolower($data['process']);
        $recent = DeviceViewData::recentProcesses($device, $device->isOnline());
        $present = collect($recent)->contains(static fn (mixed $item): bool =>
            is_array($item) && is_string($item['name'] ?? null) && mb_strtolower($item['name']) === $requested
        );
        if (! $present) {
            return response()->json(['error' => 'El proceso ya no está disponible en el inventario reciente.'], 409)
                ->header('Cache-Control', 'no-store');
        }

        try {
            $result = $knowledge->describe($data['process'], (string) $device->platform, (int) $request->user()->id);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'process_knowledge_rate_limited') {
                return response()->json(['error' => 'Se alcanzó el límite de consultas de IA por minuto.'], 429)
                    ->header('Cache-Control', 'no-store');
            }
            Log::warning('process_knowledge_unavailable', ['exception_type' => get_class($e)]);
            return response()->json(['error' => 'No se pudo consultar la información del proceso.'], 503)
                ->header('Cache-Control', 'no-store');
        } catch (Throwable $e) {
            // Deliberately exclude process names, device identity, model output and prompts.
            Log::warning('process_knowledge_unavailable', ['exception_type' => get_class($e)]);
            return response()->json(['error' => 'No se pudo consultar la información del proceso.'], 503)
                ->header('Cache-Control', 'no-store');
        }
        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
