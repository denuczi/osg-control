<?php

namespace App\Services\Reports;

use App\Jobs\GenerateDeviceReport;
use App\Jobs\GenerateFleetReport;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceReport;
use App\Models\FleetReport;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Misma creación idempotente de informes para la UI y el asistente. */
final class ReportRequestService
{
    public function __construct(private readonly ReportSettings $settings) {}

    public function fleet(int $userId): FleetReport
    {
        try {
            return Cache::lock('fleet-report-create:'.$userId, 10)->block(3,
                function () use ($userId): FleetReport {
                    return DB::transaction(function () use ($userId): FleetReport {
                        FleetReport::query()->where('user_id', $userId)
                            ->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(30))
                            ->update(['status' => 'failed', 'error_code' => 'worker_timeout', 'finished_at' => now()]);
                        FleetReport::query()->where('user_id', $userId)
                            ->where('status', 'processing')
                            ->where(function ($query): void {
                                $query->where('processing_started_at', '<=', now()->subMinutes(30))
                                    ->orWhere(function ($legacy): void {
                                        $legacy->whereNull('processing_started_at')
                                            ->where('created_at', '<=', now()->subMinutes(30));
                                    });
                            })->update(['status' => 'failed', 'error_code' => 'worker_timeout', 'finished_at' => now()]);

                        $existing = FleetReport::query()->where('user_id', $userId)
                            ->whereIn('status', ['pending', 'processing'])->latest('id')->first();
                        if ($existing !== null) return $existing;

                        abort_if(FleetReport::query()->where('user_id', $userId)
                            ->where('created_at', '>=', now()->subDay())->count() >= 10,
                            429, 'Se alcanzó el límite diario de inventarios.');

                        $report = FleetReport::query()->create([
                            'uuid' => (string) Str::uuid(), 'user_id' => $userId, 'status' => 'pending',
                        ]);
                        AuditLog::query()->create(['user_id' => $userId, 'event' => 'fleet.report.requested',
                            'context' => ['fleet_report_uuid' => $report->uuid]]);
                        GenerateFleetReport::dispatch($report->id)
                            ->onConnection('database')->onQueue('reports')->afterCommit();
                        return $report;
                    }, 3);
                });
        } catch (LockTimeoutException) {
            abort(503, 'La generación de inventarios está ocupada. Intentá nuevamente.');
        }
    }

    public function device(int $userId, Device $device): DeviceReport
    {
        abort_if($device->revoked_at || $device->archived_at || $device->is_demo, 404);
        try {
            return Cache::lock('device-report-create:'.$device->id, 10)->block(3,
            function () use ($userId, $device): DeviceReport {
                return DB::transaction(function () use ($userId, $device): DeviceReport {
                    $existing = DeviceReport::query()->where('device_id', $device->id)
                        ->whereIn('status', ['pending', 'processing'])
                        ->where('created_at', '>=', now()->subMinutes(5))->latest('id')->first();
                    if ($existing !== null) return $existing;

                    abort_if(DeviceReport::query()->where('user_id', $userId)
                        ->where('created_at', '>=', now()->subDay())->count() >=
                        (int) config('reports.max_reports_per_day_per_user', 20),
                        429, 'Límite diario de informes alcanzado.');

                    $enabled = $this->settings->enabled();
                    $report = DeviceReport::query()->create([
                        'uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'user_id' => $userId,
                        'status' => 'pending', 'ai_requested' => $enabled,
                        'ai_provider' => $enabled ? $this->settings->provider() : null,
                        'ai_status' => $enabled ? 'pending' : 'disabled',
                        'period_from' => now()->subDays(7), 'period_to' => now(),
                    ]);
                    AuditLog::query()->create(['user_id' => $userId, 'device_id' => $device->id,
                        'event' => 'report.requested', 'context' => ['report_uuid' => $report->uuid,
                            'ai_requested' => $enabled]]);
                    GenerateDeviceReport::dispatch($report->id)
                        ->onConnection('database')->onQueue('reports')->afterCommit();
                    return $report;
                }, 3);
            });
        } catch (LockTimeoutException) {
            abort(503, 'La generación de informes está ocupada. Intentá nuevamente.');
        }
    }
}
