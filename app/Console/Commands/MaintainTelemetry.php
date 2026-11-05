<?php

namespace App\Console\Commands;

use App\Models\MetricSample;
use App\Models\DeviceMediaEvent;
use App\Models\DeviceSystemEvent;
use App\Models\DeviceCommand;
use App\Models\EnrollmentCode;
use App\Models\DevicePairingRequest;
use App\Models\AuditLog;
use App\Services\MonitorSettings;
use App\Services\TelemetryCapacity;
use Illuminate\Console\Command;

/** Depuración por lotes pequeños para no monopolizar el bloqueo escritor de SQLite. */
final class MaintainTelemetry extends Command
{
    protected $signature = 'nodo:maintain-telemetry {--checkpoint : Intentar checkpoint WAL pasivo (no compacta base de datos)}';
    protected $description = 'Aplicar retención y límite de filas a las muestras históricas';

    public function handle(MonitorSettings $settings, TelemetryCapacity $capacity): int
    {
        $cutoff = now()->subDays($settings->retentionDays());
        $removed = 0;
        // Sin transacción gigante: permitir escrituras entre lotes.
        do {
            $ids = MetricSample::query()->where('recorded_at', '<', $cutoff)
                ->orderBy('id')->limit(500)->pluck('id')->all();
            if (!$ids) { break; }
            $removed += MetricSample::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === 500);

        $excess = max(0, MetricSample::query()->count() - $capacity->maxRows());
        while ($excess > 0) {
            $ids = MetricSample::query()->orderBy('recorded_at')->orderBy('id')
                ->limit(min(500, $excess))->pluck('id')->all();
            if (!$ids) { break; }
            $removed += MetricSample::query()->whereIn('id', $ids)->delete();
            $excess -= count($ids);
        }
        do {
            $ids = DeviceMediaEvent::query()->where('observed_at', '<', now()->subDays(max(1, min(3650, (int)config('nodo.media_retention_days', 180)))))
                ->orderBy('id')->limit(500)->pluck('id')->all();
            if (!$ids) { break; }
            DeviceMediaEvent::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === 500);
        // Bound the historical event count independently from the high-rate metrics.
        $limit = max(1000, (int)config('nodo.media_max_events', 50000));
        $extra = max(0, DeviceMediaEvent::query()->count() - $limit);
        while ($extra > 0) {
            $ids = DeviceMediaEvent::query()->orderBy('observed_at')->orderBy('id')
                ->limit(min(500, $extra))->pluck('id')->all();
            if (!$ids) { break; }
            DeviceMediaEvent::query()->whereIn('id', $ids)->delete();
            $extra -= count($ids);
        }
        // V6 operational events have independent retention and quota.
        // Delete oldest records in short batches to avoid long SQLite write locks.
        $eventRetention = now()->subDays(max(30, min(3650, (int) config('nodo.system_event_retention_days', 180))));
        do {
            $ids = DeviceSystemEvent::query()->where('observed_at', '<', $eventRetention)
                ->orderBy('id')->limit(500)->pluck('id')->all();
            if ($ids) { DeviceSystemEvent::query()->whereIn('id', $ids)->delete(); }
        } while (count($ids) === 500);
        $eventLimit = max(1000, (int) config('nodo.system_event_max_events', 50000));
        $eventExcess = max(0, DeviceSystemEvent::query()->count() - $eventLimit);
        while ($eventExcess > 0) {
            $ids = DeviceSystemEvent::query()->orderBy('observed_at')->orderBy('id')
                ->limit(min(500, $eventExcess))->pluck('id')->all();
            if (!$ids) { break; }
            DeviceSystemEvent::query()->whereIn('id', $ids)->delete();
            $eventExcess -= count($ids);
        }
        $adminCutoff = now()->subDays(max(30, (int) config('nodo.administrative_retention_days', 180)));
        // Mantener pendientes/dispatched hasta resolución: no borrar órdenes en curso.
        foreach ([
            [AuditLog::class, 'created_at', $adminCutoff],
            [EnrollmentCode::class, 'expires_at', now()->subDays(30)],
            [DevicePairingRequest::class, 'expires_at', now()->subDays(7)],
        ] as [$model, $column, $before]) {
            do {
                $ids = $model::query()->where($column, '<', $before)->orderBy('id')->limit(500)->pluck('id')->all();
                if ($ids) { $model::query()->whereIn('id', $ids)->delete(); }
            } while (count($ids) === 500);
        }
        do {
            $ids = DeviceCommand::query()->whereIn('status', ['completed','failed','unknown','expired'])
                ->where('finished_at', '<', $adminCutoff)->orderBy('id')->limit(500)->pluck('id')->all();
            if ($ids) { DeviceCommand::query()->whereIn('id', $ids)->delete(); }
        } while (count($ids) === 500);

        // Independent retention for compact network accounting. Keep the ledger
        // as long as the aggregates, otherwise an old replay could count twice.
        $networkCutoff = now()->subDays(\App\Services\NetworkUsage::RETENTION_DAYS);
        $networkDate = $networkCutoff->copy()
            ->timezone((string) config('nodo.network_report_timezone', 'America/Argentina/Cordoba'))
            ->format('Y-m-d');
        foreach ([
            ['network_usage_buckets', 'hour_utc', $networkCutoff],
            ['network_usage_hourly', 'hour_utc', $networkCutoff],
            ['network_usage_daily', 'day', $networkDate],
        ] as [$table, $dateColumn, $limitDate]) {
            do {
                $ids = \Illuminate\Support\Facades\DB::table($table)
                    ->where($dateColumn, '<', $limitDate)->orderBy('id')->limit(500)->pluck('id')->all();
                if ($ids) {
                    \Illuminate\Support\Facades\DB::table($table)->whereIn('id', $ids)->delete();
                }
            } while (count($ids) === 500);
        }

        if ($this->option('checkpoint') && \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite') {
            // PASSIVE never waits for active readers; check busy pages in logs.
            $result = \Illuminate\Support\Facades\DB::select('PRAGMA wal_checkpoint(PASSIVE)');
            $this->info('WAL checkpoint PASSIVE: '.json_encode($result));
        }
        $bytes = $capacity->databaseBytes();
        $this->info("Muestras depuradas: {$removed}; SQLite actual: ".($bytes === null ? 'N/D' : round($bytes / 1048576, 1).' MiB'));
        if ($bytes !== null && $bytes >= $capacity->maxBytes()) {
            $this->warn('El archivo sigue sobre la cuota: SQLite reutiliza páginas. Programar VACUUM fuera de horario, con backup y sin conexiones activas.');
        }
        return self::SUCCESS;
    }
}
