<?php

namespace App\Http\Controllers\Web;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\MetricSample;
use App\Services\TelemetryCapacity;
use App\Services\MonitorSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController
{
    public function index(MonitorSettings $settings): Response
    {
        $capacity = app(TelemetryCapacity::class);
        return Inertia::render('Settings/Index', [
            'reports' => ['ai_enabled' => app(\App\Services\Reports\ReportSettings::class)->enabled(),
                'ai_configured' => app(\App\Services\Reports\ReportSettings::class)->configured(),
                'provider' => app(\App\Services\Reports\ReportSettings::class)->provider()],
            'settings' => $settings->all(), 'ranges' => MonitorSettings::RANGES,
            'timezoneOptions' => \App\Services\DisplayTimezone::choices(),
            'selectedTimezone' => app(\App\Services\DisplayTimezone::class)->current(),
            'assistantRuntime' => [
                'mode' => app(\App\Domain\AI\AssistantRuntime::class)->mode(),
                'enabled' => (bool) config('assistant.enabled'),
                'limits' => app(\App\Domain\AI\AssistantRuntime::class)->settings(),
                'ranges' => \App\Domain\AI\AssistantRuntime::FIELDS,
            ],
            'storage' => [
                'sample_count' => MetricSample::query()->count(),
                'sample_limit' => $capacity->maxRows(),
                'database_bytes' => $capacity->databaseBytes(),
                'database_limit_bytes' => $capacity->maxBytes(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (MonitorSettings::RANGES as $key => [$min, $max]) {
            $rules[$key] = ['required', 'integer', 'between:'.$min.','.$max];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($data, $request): void {
            $before = app(MonitorSettings::class)->all();
            foreach ($data as $key => $value) {
                SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => (int) $value]);
            }
            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'event' => 'settings.updated',
                'context' => ['before' => $before, 'after' => array_map('intval', $data)],
            ]);
        });
        return back()->with('success', 'Configuración guardada. Los agentes recibirán los nuevos intervalos en la siguiente sincronización.');
    }
}
