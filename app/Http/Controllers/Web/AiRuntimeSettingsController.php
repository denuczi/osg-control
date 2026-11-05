<?php

namespace App\Http\Controllers\Web;

use App\Domain\AI\AssistantRuntime;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AiRuntimeSettingsController
{
    public function update(Request $request, AssistantRuntime $runtime): RedirectResponse
    {
        $rules = [];
        foreach (AssistantRuntime::FIELDS as $name => [$min, $max]) {
            $rules[$name] = ['required', 'integer', "between:{$min},{$max}"];
        }
        $validated = $request->validate($rules);
        if ($validated['provider_timeout_seconds'] > $validated['total_timeout_seconds']) {
            throw ValidationException::withMessages([
                'provider_timeout_seconds' => 'El límite por llamada no puede superar el tiempo total de una consulta.',
            ]);
        }
        $mode = $runtime->mode();
        $before = $runtime->settings();
        $prefix = $mode === 'local' ? 'assistant_local_' : 'assistant_remote_';
        DB::transaction(function () use ($request, $before, $validated, $mode, $prefix): void {
            foreach ($validated as $key => $value) {
                SystemSetting::query()->updateOrCreate(['key' => $prefix.$key], ['value' => (int) $value]);
            }
            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'event' => 'ai.runtime_settings_updated',
                'context' => ['mode' => $mode, 'before' => $before, 'after' => $validated],
            ]);
        });
        return back()->with('success', 'Límites del asistente guardados. Se aplicarán a las siguientes consultas.');
    }
}
