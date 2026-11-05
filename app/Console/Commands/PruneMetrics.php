<?php

namespace App\Console\Commands;

use App\Models\MetricSample;
use Illuminate\Console\Command;

class PruneMetrics extends Command
{
    protected $signature = 'nodo:prune-metrics';
    protected $description = 'Eliminar métricas de más antigüedad que el período de retención';

    public function handle(): int
    {
        $count = MetricSample::query()->where('recorded_at', '<', now()->subDays(app(\App\Services\MonitorSettings::class)->retentionDays()))->delete();
        $this->info("Métricas antiguas eliminadas: {$count}");
        return self::SUCCESS;
    }
}
