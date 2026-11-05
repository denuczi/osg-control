<?php

namespace App\Http\Controllers\Web;

use App\Models\AuditLog;
use App\Services\DisplayTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Admin-only route; no agent can change the installation's presentation zone. */
final class DisplayTimezoneController
{
    public function update(Request $request, DisplayTimezone $timezone): RedirectResponse
    {
        $input = $request->validate([
            'timezone' => ['required', 'string', 'max:80', Rule::in(DisplayTimezone::identifiers())],
        ]);
        DB::transaction(function () use ($request, $timezone, $input): void {
            $previous = $timezone->current();
            $timezone->update($input['timezone']);
            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'event' => 'settings.timezone.updated',
                'context' => ['before' => $previous, 'after' => $input['timezone']],
            ]);
        });
        return back()->with('success', 'Zona horaria actualizada. Las fechas registradas se conservaron sin cambios.');
    }
}
