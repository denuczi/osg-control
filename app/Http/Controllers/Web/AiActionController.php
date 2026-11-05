<?php

namespace App\Http\Controllers\Web;

use App\Models\AiActionProposal;
use App\Models\AuditLog;
use App\Services\BulkCommandService;
use App\Services\Reports\ReportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AiActionController
{
    public function confirm(Request $request, AiActionProposal $proposal, BulkCommandService $commands, ReportSettings $settings): JsonResponse
    {
        abort_unless(config('assistant.enabled') && $settings->configured(), 409, 'El asistente está deshabilitado. No se ejecutó ninguna orden.');
        $request->validate(['confirm' => ['required', 'accepted']]);
        // La propuesta siempre es propiedad de un administrador concreto.
        abort_unless((int) $proposal->user_id === (int) $request->user()->id, 404);
        $registered = DB::transaction(function () use ($proposal, $request, $commands): int {
            $current = AiActionProposal::query()->whereKey($proposal->uuid)->lockForUpdate()->firstOrFail();
            abort_unless((int) $current->user_id === (int) $request->user()->id, 404);
            if ($current->status === 'confirmed') {
                // Reintentos HTTP idempotentes: nunca recrear órdenes.
                return count($current->device_uuids ?? []);
            }
            abort_unless($current->status === 'pending', 409, 'La propuesta ya no está disponible.');
            abort_if($current->expires_at->isPast(), 410, 'La propuesta venció. Solicitá una nueva.');
            $count = $commands->dispatch(
                (int) $request->user()->id,
                $current->action,
                $current->batch_uuid,
                $current->device_uuids,
                false,
                (string) ($current->message ?? ''),
            );
            $current->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            AuditLog::query()->create(['user_id' => $request->user()->id, 'event' => 'ai.action_confirmed', 'context' => [
                'proposal_uuid' => $current->uuid, 'batch_uuid' => $current->batch_uuid,
                'action' => $current->action, 'recipients' => $count,
            ]]);
            return $count;
        }, 3);
        return response()->json([
            'status' => 'registered', 'orders_registered' => $registered,
            'message' => "Se registraron {$registered} órdenes. Su ejecución depende de cada equipo.",
        ])->header('Cache-Control', 'no-store');
    }
    public function cancel(Request $request, AiActionProposal $proposal): JsonResponse
    {
        abort_unless((int) $proposal->user_id === (int) $request->user()->id, 404);
        DB::transaction(function () use ($request, $proposal): void {
            $current = AiActionProposal::query()->whereKey($proposal->uuid)->lockForUpdate()->firstOrFail();
            abort_unless((int) $current->user_id === (int) $request->user()->id, 404);
            if ($current->status === 'cancelled') return; // Idempotent cancellation.
            abort_unless($current->status === 'pending', 409, 'La propuesta ya no se puede cancelar.');
            $current->update(['status' => 'cancelled']);
            AuditLog::query()->create(['user_id' => $request->user()->id,
                'event' => 'ai.action_cancelled',
                'context' => ['proposal_uuid' => $current->uuid, 'action' => $current->action]]);
        }, 3);
        return response()->json(['status' => 'cancelled'])->header('Cache-Control', 'no-store');
    }

}
