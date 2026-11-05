<?php

namespace App\Console\Commands;

use App\Models\DeviceReport;
use App\Models\FleetReport;
use Illuminate\Console\Command;

final class PruneDeviceReports extends Command
{
    protected $signature = 'nodo:prune-reports';
    protected $description = 'Depura informes vencidos y solicitudes abandonadas sin afectar el historial del dispositivo.';

    public function handle(): int
    {
        DeviceReport::query()->whereIn('status', ['pending','processing'])
            ->where('created_at', '<', now()->subMinutes(30))
            ->update(['status' => 'failed', 'error_code' => 'worker_timeout', 'finished_at' => now()]);
        $prefix = storage_path('app/private/reports').'/';
        DeviceReport::query()->where('created_at', '<', now()->subDays((int) config('reports.retention_days', 30)))
            ->orderBy('id')->chunkById(100, function ($rows) use ($prefix): void {
                foreach ($rows as $row) {
                    if (is_string($row->file_path) && str_starts_with($row->file_path, $prefix)) {
                        @unlink($row->file_path);
                    }
                    $row->delete();
                }
            });
        // Un proceso activo no debe marcarse fallido solo por la espera en cola.
        FleetReport::query()->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))
            ->update(['status' => 'failed', 'error_code' => 'worker_timeout', 'finished_at' => now()]);
        FleetReport::query()->where('status', 'processing')
            ->where(function ($query): void {
                $query->where('processing_started_at', '<', now()->subMinutes(30))
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('processing_started_at')
                            ->where('created_at', '<', now()->subMinutes(30));
                    });
            })->update(['status' => 'failed', 'error_code' => 'worker_timeout', 'finished_at' => now()]);
        $fleetDirectory = storage_path('app/private/reports/fleet');
        $fleetPrefix = $fleetDirectory.'/';
        FleetReport::query()->where('created_at', '<', now()->subDays((int) config('reports.retention_days', 30)))
            ->orderBy('id')->chunkById(100, function ($rows) use ($fleetPrefix, $fleetDirectory): void {
                foreach ($rows as $row) {
                    $target = is_string($row->file_path) ? realpath($row->file_path) : false;
                    $base = realpath($fleetDirectory);
                    if ($base !== false && $target !== false && str_starts_with($target, $base.DIRECTORY_SEPARATOR)) {
                        @unlink($target);
                    }
                    $row->delete();
                }
            });
        $this->removeOrphanedFleetFiles($fleetDirectory);
        return self::SUCCESS;
    }
    /** Eliminar salidas de intentos interrumpidos, después de 48 h de gracia. */
    private function removeOrphanedFleetFiles(string $directory): void
    {
        // Los tests usan una base temporal, pero pueden compartir storage/ con
        // la instalación; jamás borrar archivos reales basándose en esa base.
        if (app()->environment('testing')) return;
        $base = realpath($directory);
        if ($base === false) return;
        $candidates = [];
        foreach (new \DirectoryIterator($base) as $entry) {
            if (! $entry->isFile() || $entry->isLink()) continue;
            $name = $entry->getFilename();
            if (! preg_match('/^(?:[0-9a-f-]{36}|[0-9a-f-]{36}-[0-9a-f-]{36})\.pdf$/D', $name) ||
                $entry->getMTime() >= time() - 48 * 3600) continue;
            $candidates[] = $entry->getPathname();
            if (count($candidates) >= 1000) break;
        }
        if ($candidates === []) return;
        $referenced = FleetReport::query()->whereIn('file_path', $candidates)->pluck('file_path')->all();
        $keep = array_fill_keys($referenced, true);
        foreach ($candidates as $path) {
            if (! isset($keep[$path])) @unlink($path);
        }
    }
}
