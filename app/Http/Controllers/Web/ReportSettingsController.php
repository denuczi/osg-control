<?php

namespace App\Http\Controllers\Web;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\Reports\ReportSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReportSettingsController
{
    public function update(Request $request, ReportSettings $settings): RedirectResponse
    {
        $data = $request->validate(['ai_enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['ai_enabled'];
        if ($enabled && ! $settings->configured()) {
            return back()->withErrors(['ai_enabled' => 'Configurá el proveedor, modelo y clave API en Debian antes de activar la IA.']);
        }
        DB::transaction(function () use ($enabled, $request, $settings): void {
            $before = $settings->enabled();
            SystemSetting::query()->updateOrCreate(['key' => ReportSettings::AI_KEY], ['value' => (int) $enabled]);
            AuditLog::query()->create(['user_id' => $request->user()->id,
                'event' => 'reports.ai.settings.updated',
                'context' => ['before' => $before, 'after' => $enabled]]);
        }, 3);
        return back()->with('success', 'Configuración de informes actualizada.');
    }
}
