<?php

namespace App\Http\Controllers\Web;

use App\Domain\AI\AssistantService;
use App\Domain\AI\AssistantRuntime;
use App\Models\Device;
use App\Services\Reports\ReportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class AiAssistantController
{
    public function index(Request $request, ReportSettings $settings): Response
    {
        $uuid = $request->query('device');
        $context = null;
        if (is_string($uuid) && strlen($uuid) === 36) {
            $device = Device::query()->where('uuid', $uuid)->whereNull('revoked_at')->whereNull('archived_at')
                ->first(['uuid', 'name']);
            if ($device) $context = ['uuid' => $device->uuid, 'name' => $device->name];
        }
        return Inertia::render('Assistant/Index', [
            'available' => (bool) config('assistant.enabled') && $settings->configured(),
            'deviceContext' => $context,
        ]);
    }

    public function ask(Request $request, AssistantService $assistant, ReportSettings $settings, AssistantRuntime $runtime): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1200'],
            'history' => ['sometimes', 'array', 'max:6'],
            'conversation_id' => ['sometimes', 'uuid'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:1200'],
            'device_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:devices,uuid'],
        ]);
        if (trim($data['question']) === '') {
            return response()->json(['message' => 'Escribí una pregunta.'], 422)
                ->header('Cache-Control', 'no-store');
        }
        if (! config('assistant.enabled') || ! $settings->configured()) {
            return response()->json(['error' => 'El asistente todavía no está habilitado o configurado.'], 503)
                ->header('Cache-Control', 'no-store');
        }
        try {
            $result = $assistant->ask(
                (int) $request->user()->id,
                trim($data['question']),
                $data['history'] ?? [],
                $data['device_uuid'] ?? null,
                $data['conversation_id'] ?? null,
            );
            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (Throwable $e) {
            // No registrar prompts, credenciales, respuesta remota ni detalles de dispositivos.
            $errorCode = $e->getMessage();
            $budgetExceeded = in_array($errorCode, [
                'assistant_total_time_budget_exceeded', 'assistant_provider_call_budget_exceeded',
            ], true);
            Log::warning('ai_assistant_request_failed', [
                'exception_type' => get_class($e), 'performance' => $runtime->stats(),
                'reason' => $budgetExceeded ? 'configured_budget_exceeded' : 'provider_or_processing_error',
            ]);
            $message = $budgetExceeded
                ? 'La consulta alcanzó los límites de tiempo o llamadas configurados para el asistente. Revisá «Configuración → Rendimiento del asistente IA» o realizá una pregunta más concreta.'
                : 'No se pudo completar la consulta. Comprobá que el modelo configurado esté disponible y volvé a intentarlo.';
            return response()->json(['error' => $message], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
