<?php

namespace App\Jobs;

use App\Models\FleetReport;
use App\Services\Reports\Contracts\FleetRenderer;
use App\Services\Reports\FleetInventoryCollector;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class GenerateFleetReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 110;
    public bool $failOnTimeout = false;

    public function __construct(public readonly int $reportId) {}

    public function handle(FleetInventoryCollector $collector, FleetRenderer $renderer): void
    {
        $executionId = (string) Str::uuid();
        $now = now();

        // Claim atómico: solo un worker activo. Un reintento puede recuperar
        // una ejecución interrumpida después de su plazo máximo.
        $claimed = DB::table('fleet_reports')->where('id', $this->reportId)
            ->where(function ($query) use ($now): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($now): void {
                        $stale->where('status', 'processing')
                            ->where('processing_started_at', '<=', $now->copy()->subSeconds(150));
                    });
            })->update([
                'status' => 'processing', 'run_token' => $executionId,
                'processing_started_at' => $now, 'updated_at' => $now,
            ]);
        if ($claimed !== 1) return;

        $file = null;
        try {
            // Timestamp real de la captura, no el de la solicitud HTTP.
            $data = $collector->collect(CarbonImmutable::now());
            $report = FleetReport::query()->findOrFail($this->reportId);
            $file = $renderer->render($report->uuid, $data, $executionId);

            $updated = $this->ownedQuery($executionId)->update([
                'status' => 'ready', 'file_path' => $file,
                'device_count' => $data['count'], 'error_code' => null,
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                // Otro intento/administrador invalidó la ejecución. No publicar el archivo.
                @unlink($file);
            }
        } catch (Throwable $exception) {
            if ($file !== null) @unlink($file);
            $reason = $exception instanceof RuntimeException && $exception->getMessage() === 'fleet_device_limit_exceeded'
                ? 'fleet_device_limit_exceeded' : 'generation_failed';
            $this->ownedQuery($executionId)->update([
                'status' => 'failed', 'error_code' => $reason,
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            Log::error('Fleet inventory generation failed', [
                'report_id' => $this->reportId,
                'exception_type' => get_class($exception),
                'reason' => $reason,
            ]);
        }
    }

    private function ownedQuery(string $executionId): \Illuminate\Database\Query\Builder
    {
        return DB::table('fleet_reports')->where('id', $this->reportId)
            ->where('status', 'processing')->where('run_token', $executionId);
    }

    public function failed(?Throwable $exception): void
    {
        // Último intento agotado por timeout: no dejar la fila esperando indefinidamente.
        DB::table('fleet_reports')->where('id', $this->reportId)
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($query): void {
                $query->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<=', now()->subSeconds(100));
            })->update([
                'status' => 'failed', 'error_code' => 'worker_timeout',
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }
}
