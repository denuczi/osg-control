<?php

namespace Tests\Feature;

use App\Jobs\GenerateFleetReport;
use App\Models\Device;
use App\Models\DeviceDiagnostic;
use App\Models\DeviceInventory;
use App\Models\FleetReport;
use App\Models\User;
use App\Services\Reports\FleetInventoryCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FleetInventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin): User
    {
        return User::query()->create([
            'name' => 'QA', 'email' => Str::uuid().'@example.test',
            'password' => 'secret-test-12345', 'is_admin' => $admin,
        ]);
    }

    private function device(string $name, int $ramGib, int $cores, bool $battery, bool $removable = false): Device
    {
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name,
            'hostname' => $name, 'platform' => 'Windows 11 Pro',
            'token_hash' => hash('sha256', Str::random(32)),
            'memory_total_bytes' => $ramGib * 1073741824,
        ]);
        DeviceInventory::query()->create([
            'device_id' => $device->id, 'captured_at' => now(),
            'processor' => [
                'name' => 'Procesador '.$name,
                'physical_cores' => $cores,
                'logical_processors' => $cores * 2,
                'max_mhz' => 3600,
            ],
            'volumes' => [
                ['mountpoint' => 'C:\\', 'total_bytes' => 512 * 1073741824, 'removable' => false],
                ['mountpoint' => 'D:\\', 'total_bytes' => 64 * 1073741824, 'removable' => true],
                ['mountpoint' => 'C:\\', 'total_bytes' => 512 * 1073741824, 'removable' => false],
            ],
        ]);
        DeviceDiagnostic::query()->create([
            'device_id' => $device->id, 'captured_at' => now(), 'received_at' => now(),
            'hardware' => ['status' => 'ok', 'data' => ['Manufacturer' => 'Fabricante', 'Model' => 'Modelo', 'BatteryPresent' => $battery]],
            'disks' => ['status' => 'unavailable', 'data' => null],
            'security' => ['status' => 'ok', 'data' => ['Windows' => ['Caption' => 'Microsoft Windows 11 Pro', 'DisplayVersion' => '24H2']]],
        ]);
        return $device;
    }

    public function test_admin_can_request_fleet_export_and_reuse_pending_job(): void
    {
        Bus::fake();
        $this->actingAs($this->user(true));
        $first = $this->postJson(route('fleet-reports.create'))->assertStatus(202)->json('uuid');
        $second = $this->postJson(route('fleet-reports.create'))->assertStatus(202)->json('uuid');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('fleet_reports', 1);
        Bus::assertDispatched(GenerateFleetReport::class, 1);
    }

    public function test_export_requires_admin_authentication(): void
    {
        $this->postJson(route('fleet-reports.create'))->assertUnauthorized();
        $this->actingAs($this->user(false))->postJson(route('fleet-reports.create'))->assertForbidden();
        $this->assertDatabaseCount('fleet_reports', 0);
    }

    public function test_inventory_orders_by_declared_cores_and_ram_without_counting_usb(): void
    {
        $low = $this->device('LOW', 8, 4, false);
        $high = $this->device('HIGH', 32, 12, true);
        $middle = $this->device('MIDDLE', 16, 8, true);
        $middle->update(['archived_at' => now()]);
        $low->refresh();

        $snapshot = app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
        $this->assertSame(2, $snapshot['count']);
        $this->assertSame(['HIGH', 'LOW'], array_column($snapshot['rows'], 'name'));
        $this->assertSame(20 * 1073741824.0, (float) $snapshot['summary']['ram']['value']);
        $this->assertSame(512 * 1073741824.0, (float) $snapshot['summary']['storage']['value']);
        $this->assertSame(8.0, (float) $snapshot['summary']['physical_cores']['value']);
        $this->assertSame(1, $snapshot['summary']['battery_yes']);
        $this->assertSame(2, $snapshot['summary']['battery_known']);
        $this->assertSame('Microsoft Windows 11 Pro · 24H2', $snapshot['rows'][0]['platform']);
        $this->assertSame($high->uuid, $snapshot['rows'][0]['uuid']);
    }

    public function test_missing_information_is_not_counted_as_zero_and_pdf_escapes_user_input(): void
    {
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => '<script>Hola</script>',
            'hostname' => 'PC-UNKNOWN', 'platform' => 'Windows 10',
            'token_hash' => hash('sha256', Str::random(32)),
        ]);
        $snapshot = app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
        $this->assertNull($snapshot['summary']['ram']['value']);
        $this->assertSame(0, $snapshot['summary']['battery_known']);
        $this->assertNull($snapshot['rows'][0]['storage_bytes']);
        $html = View::make('reports.fleet', [
            'inventory' => $snapshot,
            'presenter' => new \App\Services\Reports\ReportPresentation('America/Argentina/Buenos_Aires'),
        ])->render();
        $this->assertStringNotContainsString('<script>Hola</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;Hola&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('SQLite', $html);
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertSame($device->uuid, $snapshot['rows'][0]['uuid']);
    }


    public function test_fleet_pdf_renders_local_timestamps_and_balanced_blade_directives(): void
    {
        $this->device('LAB-FECHAS', 8, 4, true);
        $snapshot = app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
        // Stable UTC inputs must be displayed in Argentina local time (UTC-03:00).
        foreach (['last_seen_at', 'inventory_captured_at', 'diagnostic_captured_at'] as $field) {
            $snapshot['rows'][0][$field] = '2026-11-03T17:00:00+00:00';
        }

        $html = View::make('reports.fleet', [
            'inventory' => $snapshot,
            'presenter' => new \App\Services\Reports\ReportPresentation('America/Argentina/Buenos_Aires'),
        ])->render();

        $this->assertSame(3, substr_count($html, '03/11/2026 14:00 h'));
        $this->assertStringNotContainsString('@endif', $html);
        $this->assertStringNotContainsString('@endforeach', $html);
    }

    public function test_pdf_renders_demo_badges_and_uneven_card_grid_without_unclosed_directives(): void
    {
        $demo = $this->device('LAB-DEMO', 16, 8, true);
        $demo->update(['is_demo' => true]);

        // A single device also exercises the filler cell at the end of the grid.
        $snapshot = app(FleetInventoryCollector::class)->collect(CarbonImmutable::now());
        $this->assertSame(1, $snapshot['count']);
        $snapshot['rows'][0]['storage_scope'] = 'primary_volume';
        $snapshot['rows'][0]['storage_media'] = null;

        $html = View::make('reports.fleet', [
            'inventory' => $snapshot,
            'presenter' => new \App\Services\Reports\ReportPresentation('America/Argentina/Buenos_Aires'),
        ])->render();

        $this->assertStringContainsString('Equipo de demostración', $html);
        $this->assertStringContainsString('Unidad principal', $html);
        $this->assertStringNotContainsString('@endif', $html);
        $this->assertStringNotContainsString('@endforeach', $html);
    }

    public function test_report_is_not_downloadable_until_ready_and_path_is_private(): void
    {
        $this->actingAs($this->user(true));
        $report = FleetReport::query()->create(['uuid' => (string) Str::uuid(), 'status' => 'pending']);
        $this->get(route('fleet-reports.download', $report))->assertNotFound();
        $report->update(['status' => 'ready', 'file_path' => '/tmp/not-a-private-report.pdf']);
        $this->get(route('fleet-reports.download', $report))->assertNotFound();
    }
}
