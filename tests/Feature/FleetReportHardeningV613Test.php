<?php

namespace Tests\Feature;

use App\Jobs\GenerateFleetReport;
use App\Models\Device;
use App\Models\DeviceInventory;
use App\Models\FleetReport;
use App\Services\Reports\Contracts\FleetRenderer;
use App\Services\Reports\FleetInventoryCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class FleetReportHardeningV613Test extends TestCase
{
    use RefreshDatabase;

    private function device(string $name, array $volumes = [], array $processor = [], array $attrs = []): Device
    {
        $device = Device::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => $name, 'hostname' => $name, 'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()),
        ], $attrs));
        DeviceInventory::query()->create([
            'device_id' => $device->id,
            'captured_at' => now(),
            'processor' => $processor,
            'volumes' => $volumes,
        ]);
        return $device;
    }

    private function fakeRenderer(?\Closure $beforeReturn = null): FleetRenderer
    {
        return new class($beforeReturn) implements FleetRenderer {
            public int $calls = 0;
            public array $files = [];
            public function __construct(private ?\Closure $beforeReturn) {}
            public function render(string $uuid, array $inventory, string $executionId): string
            {
                $this->calls++;
                $path = tempnam(sys_get_temp_dir(), 'nodo-fleet-qa-');
                if ($path === false) throw new RuntimeException('test_temp_failed');
                file_put_contents($path, '%PDF-Test');
                $this->files[] = $path;
                if ($this->beforeReturn !== null) ($this->beforeReturn)();
                return $path;
            }
        };
    }

    public function test_invalid_numbers_are_not_silently_converted_and_remote_volumes_are_not_summed(): void
    {
        $gib = 1073741824;
        $this->device('LAB-01', [
            ['mountpoint' => 'C:\\', 'total_bytes' => 512 * $gib, 'removable' => false, 'media_type' => 'SSD'],
            ['mountpoint' => 'C:/', 'total_bytes' => 512 * $gib, 'removable' => false],
            ['mountpoint' => 'Z:\\', 'device' => '\\\\server\\share', 'total_bytes' => 900 * $gib, 'removable' => false],
            ['mountpoint' => 'D:\\', 'total_bytes' => 64 * $gib, 'removable' => true],
        ], [
            'physical_cores' => '8.5', 'logical_processors' => 12,
            'max_mhz' => 3600.5,
        ], ['memory_total_bytes' => 16 * $gib]);

        $snapshot = app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
        $row = $snapshot['rows'][0];
        $this->assertNull($row['physical_cores']);
        $this->assertSame(12, $row['logical_processors']);
        $this->assertSame(3600.5, $row['max_mhz']);
        $this->assertSame(512 * $gib, $row['storage_bytes']);
        $this->assertSame('declared_volumes', $row['storage_scope']);
        $this->assertSame('SSD', $row['storage_media']);
        $this->assertNull($snapshot['summary']['physical_cores']['value']);
    }

    public function test_primary_unit_fallback_is_labeled_and_excluded_from_volume_average(): void
    {
        $this->device('SIN-INVENTARIO', [], [], [
            'disk_total_bytes' => 200 * 1073741824,
        ]);
        $snapshot = app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
        $this->assertSame('primary_volume', $snapshot['rows'][0]['storage_scope']);
        $this->assertSame(200 * 1073741824, $snapshot['rows'][0]['storage_bytes']);
        $this->assertSame(0, $snapshot['summary']['storage']['known']);
        $this->assertNull($snapshot['summary']['storage']['value']);
    }

    public function test_export_is_bounded_before_loading_all_devices(): void
    {
        config()->set('reports.fleet_max_devices', 2);
        foreach (range(1, 3) as $i) $this->device('LAB-'.$i);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fleet_device_limit_exceeded');
        app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
    }

    public function test_collector_uses_bounded_batches_without_per_device_relation_queries(): void
    {
        config()->set('reports.fleet_max_devices', 100);
        foreach (range(1, 45) as $i) $this->device('LAB-'.$i);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $snapshot = app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(45, $snapshot['count']);
        $this->assertLessThan(15, count($queries));
    }

    public function test_active_execution_cannot_be_claimed_by_another_worker(): void
    {
        $report = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'processing',
            'run_token' => (string) Str::uuid(), 'processing_started_at' => now(),
        ]);
        $renderer = $this->fakeRenderer();
        (new GenerateFleetReport($report->id))->handle(app(FleetInventoryCollector::class), $renderer);
        $this->assertSame(0, $renderer->calls);
        $this->assertSame('processing', $report->fresh()->status);
    }

    public function test_retry_reclaims_stale_processing_with_a_new_execution_token(): void
    {
        $originalToken = (string) Str::uuid();
        $report = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'processing',
            'run_token' => $originalToken,
            'processing_started_at' => now()->subMinutes(5),
        ]);
        $renderer = $this->fakeRenderer();
        try {
            (new GenerateFleetReport($report->id))->handle(app(FleetInventoryCollector::class), $renderer);
            $fresh = $report->fresh();
            $this->assertSame('ready', $fresh->status);
            $this->assertNotSame($originalToken, $fresh->run_token);
            $this->assertSame(1, $renderer->calls);
        } finally {
            foreach ($renderer->files as $file) @unlink($file);
        }
    }

    public function test_superseded_worker_never_publishes_its_pdf(): void
    {
        $report = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
        ]);
        $anotherToken = (string) Str::uuid();
        $renderer = $this->fakeRenderer(function () use ($report, $anotherToken): void {
            FleetReport::query()->whereKey($report->id)->update(['run_token' => $anotherToken]);
        });
        (new GenerateFleetReport($report->id))->handle(app(FleetInventoryCollector::class), $renderer);
        $this->assertSame('processing', $report->fresh()->status);
        $this->assertSame($anotherToken, $report->fresh()->run_token);
        $this->assertNull($report->fresh()->file_path);
        $this->assertFileDoesNotExist($renderer->files[0]);
    }

    public function test_queue_retry_window_exceeds_fleet_worker_timeout(): void
    {
        $this->assertGreaterThan(120, config('queue.connections.database.retry_after'));
        $this->assertLessThan(config('queue.connections.database.retry_after'), (new GenerateFleetReport(1))->timeout);
    }
    public function test_maintenance_marks_abandoned_reports_but_not_active_processing(): void
    {
        $waiting = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
        ]);
        $waiting->forceFill(['created_at' => now()->subHours(1)])->save();
        $active = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'processing',
            'processing_started_at' => now(), 'run_token' => (string) Str::uuid(),
        ]);
        $stale = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'processing',
            'processing_started_at' => now()->subHours(1), 'run_token' => (string) Str::uuid(),
        ]);
        $this->artisan('nodo:prune-reports')->assertSuccessful();
        $this->assertSame('failed', $waiting->fresh()->status);
        $this->assertSame('processing', $active->fresh()->status);
        $this->assertSame('failed', $stale->fresh()->status);
    }

    public function test_renderer_failure_marks_report_failed_without_publishing_a_file(): void
    {
        $report = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
        ]);
        $renderer = new class implements FleetRenderer {
            public function render(string $uuid, array $inventory, string $executionId): string
            {
                throw new RuntimeException('render_test_failure');
            }
        };
        (new GenerateFleetReport($report->id))->handle(app(FleetInventoryCollector::class), $renderer);
        $fresh = $report->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('generation_failed', $fresh->error_code);
        $this->assertNull($fresh->file_path);
    }

    public function test_limit_failure_is_reported_without_attempting_pdf_rendering(): void
    {
        config()->set('reports.fleet_max_devices', 1);
        $this->device('PRIMERO');
        $this->device('SEGUNDO');
        $report = FleetReport::query()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
        ]);
        $renderer = $this->fakeRenderer();
        (new GenerateFleetReport($report->id))->handle(app(FleetInventoryCollector::class), $renderer);
        $this->assertSame('failed', $report->fresh()->status);
        $this->assertSame('fleet_device_limit_exceeded', $report->fresh()->error_code);
        $this->assertSame(0, $renderer->calls);
    }

}
