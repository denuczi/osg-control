<?php

namespace App\Services;

use App\Models\MetricSample;
use Illuminate\Support\Facades\DB;

/** Soft physical limit + hard row limit for history; never blocks vital commands. */
final class TelemetryCapacity
{
    public function maxRows(): int
    {
        return max(1000, (int) config('nodo.telemetry_max_rows', 150000));
    }

    public function maxBytes(): int
    {
        return max(32, (int) config('nodo.telemetry_max_mib', 256)) * 1048576;
    }

    public function databaseBytes(): ?int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') { return null; }
        $path = DB::connection()->getDatabaseName();
        if (!$path || $path === ':memory:' || !is_file($path)) { return null; }
        clearstatcache(true, $path);
        $bytes = filesize($path);
        if ($bytes === false) { return null; }
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($path.$suffix)) { $bytes += filesize($path.$suffix) ?: 0; }
        }
        return $bytes;
    }

    public function sqliteFreeBytes(): ?int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') { return null; }
        $db = DB::connection();
        $page = $db->selectOne('PRAGMA page_size');
        $free = $db->selectOne('PRAGMA freelist_count');
        return ((int) ($page->page_size ?? 0)) * ((int) ($free->freelist_count ?? 0));
    }

    public function prepare(int $incoming, ?int $deviceId = null): bool
    {
        if ($incoming < 0 || $incoming > $this->maxRows()) { return false; }
        $excess = max(0, MetricSample::query()->count() + $incoming - $this->maxRows());
        while ($excess > 0) {
            // Trim the most represented device first, independent of who is
            // sending the new sample. One noisy host must not evict all others.
            $query = MetricSample::query();
            $mostRepresented = MetricSample::query()->select('device_id')
                ->groupBy('device_id')->orderByRaw('COUNT(*) DESC')->value('device_id');
            if ($mostRepresented !== null) {
                $query->where('device_id', $mostRepresented);
            }
            $ids = $query->orderBy('recorded_at')->orderBy('id')
                ->limit(min(500, $excess))->pluck('id')->all();
            if (!$ids) { break; }
            MetricSample::query()->whereIn('id', $ids)->delete();
            $excess -= count($ids);
        }
        if ($excess !== 0) { return false; }
        $bytes = $this->databaseBytes();
        if ($bytes !== null && $bytes >= $this->maxBytes()) {
            $reusable = $this->sqliteFreeBytes() ?? 0;
            if ($reusable < 1024 * 1024) {
                // Bounded cleanup creates reusable pages; deleting does not shrink
                // the file. A high file-size alone must not deadlock ingestion.
                $ids = MetricSample::query()->orderBy('recorded_at')->limit(500)->pluck('id')->all();
                if ($ids) { MetricSample::query()->whereIn('id', $ids)->delete(); }
                $reusable = $this->sqliteFreeBytes() ?? 0;
                if ($reusable < 1024 * 1024 && $bytes > $this->maxBytes() + 8 * 1048576) {
                    return false;
                }
            }
        }
        return true;
    }
}
