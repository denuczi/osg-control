<?php

namespace App\Http\Controllers\Web;

use App\Services\BulkCommandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Entrada existente; delega la validación de dominio a un servicio compartido. */
final class BulkCommandController
{
    public function __invoke(Request $request, BulkCommandService $commands): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:restart,shutdown,show_message'],
            'batch_uuid' => ['required', 'uuid'],
            'device_uuids' => ['required_unless:all_online,true', 'array', 'max:100'],
            'all_online' => ['sometimes', 'boolean'],
            'device_uuids.*' => ['required', 'uuid', 'distinct'],
            'message' => ['required_if:action,show_message', 'nullable', 'string', 'max:500'],
            'confirm' => ['required', 'accepted'],
        ]);
        $count = $commands->dispatch(
            (int) $request->user()->id,
            $data['action'],
            $data['batch_uuid'],
            $data['device_uuids'] ?? [],
            (bool) ($data['all_online'] ?? false),
            (string) ($data['message'] ?? ''),
        );
        return back()->with('success', "Se registraron {$count} órdenes individuales.");
    }
}
