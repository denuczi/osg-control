<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Device;
use App\Models\User;
use App\Services\DisplayTimezone;
use App\Services\NetworkUsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TimezoneSelectionAndHistoryV632Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin', 'email' => Str::uuid().'@example.test',
            'password' => 'password', 'is_admin' => true,
        ]);
    }

    private function device(): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC prueba', 'hostname' => 'PC-TEST',
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', (string) Str::uuid()),
        ]);
    }

    public function test_settings_expose_readable_country_city_selectors_for_common_destinations(): void
    {
        $this->actingAs($this->admin());
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) (app(HandleInertiaRequests::class)->version(request()) ?? ''),
        ])->get('/configuracion')->assertOk()->assertJsonPath('component', 'Settings/Index');
        $options = collect($response->json('props.timezoneOptions'));
        foreach ([
            'America/Argentina/Cordoba' => 'AR', 'America/Bogota' => 'CO',
            'America/Mexico_City' => 'MX', 'Europe/Madrid' => 'ES',
            'America/New_York' => 'US', 'UTC' => 'ZZ',
        ] as $zone => $country) {
            $this->assertTrue($options->contains(fn ($choice) => $choice['id'] === $zone && $choice['country'] === $country), $zone);
        }
        $this->assertSame('America/Argentina/Cordoba', $response->json('props.selectedTimezone'));
        $this->assertArrayNotHasKey('timezones', $response->json('props'));
    }

    public function test_switching_zone_changes_calendar_period_and_preserves_utc_data(): void
    {
        CarbonImmutable::setTestNow('2026-11-04T01:30:00Z');
        try {
            $this->actingAs($this->admin());
            $this->put('/configuracion/zona-horaria', ['timezone' => 'America/New_York'])->assertRedirect();
            $period = app(NetworkUsageReport::class)->period(request()->merge(['period' => 'today']));
            $this->assertSame('2026-11-03', $period['from']);
            $this->assertSame('America/New_York', $period['timezone']);
            $this->assertDatabaseHas('display_settings', ['key' => 'timezone', 'value' => 'America/New_York']);
            $this->assertSame('UTC', config('app.timezone'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_hourly_network_traffic_is_split_at_midnight_in_selected_zone(): void
    {
        $device = $this->device();
        DB::table('network_usage_hourly')->insert([
            'device_id' => $device->id, 'hour_utc' => '2026-11-04 18:00:00',
            'rx_bytes' => 3600, 'tx_bytes' => 7200, 'observed_seconds' => 3600,
        ]);
        DB::table('network_usage_daily')->insert([
            'device_id' => $device->id, 'day' => '2026-11-04',
            'rx_bytes' => 3600, 'tx_bytes' => 7200, 'observed_seconds' => 3600,
        ]);
        $this->actingAs($this->admin());
        $this->put('/configuracion/zona-horaria', ['timezone' => 'Asia/Kolkata'])->assertRedirect();
        $range = ['period' => 'custom', 'from' => '2026-11-04', 'to' => '2026-11-05', 'timezone' => 'Asia/Kolkata'];
        $report = app(NetworkUsageReport::class)->data($range);
        $this->assertSame('Asia/Kolkata', $report['timezone']);
        $this->assertSame('2026-11-04', $report['daily'][0]['day']);
        $this->assertSame(1800, $report['daily'][0]['rx_bytes']);
        $this->assertSame(3600, $report['daily'][0]['tx_bytes']);
        $this->assertSame('2026-11-05', $report['daily'][1]['day']);
        $this->assertSame(1800, $report['daily'][1]['rx_bytes']);
        $this->assertSame(3600, $report['daily'][1]['tx_bytes']);
        $this->assertSame(1800, $report['daily'][0]['observed_seconds']);
        $this->assertSame(1800, $report['daily'][1]['observed_seconds']);
        $this->assertSame(10800, $report['total_bytes']);
        $this->assertSame(2, $report['days_with_data']);
        $this->assertDatabaseHas('network_usage_daily', ['device_id' => $device->id, 'day' => '2026-11-04', 'rx_bytes' => 3600]);
        $pdf = app(\App\Services\Reports\ReportDataCollector::class)->collect(
            $device, CarbonImmutable::parse('2026-11-06T00:00:00Z'));
        $this->assertSame('Asia/Kolkata', $pdf['meta']['network_aggregation_timezone']);
        $this->assertCount(2, $pdf['network_usage_daily']);
    }

    public function test_daily_only_legacy_history_is_labeled_with_real_original_zone(): void
    {
        $device = $this->device();
        DB::table('network_usage_daily')->insert([
            'device_id' => $device->id, 'day' => '2026-11-04',
            'rx_bytes' => 1000, 'tx_bytes' => 500, 'observed_seconds' => 60,
        ]);
        $result = app(NetworkUsageReport::class)->data([
            'period' => 'custom', 'from' => '2026-11-04', 'to' => '2026-11-04',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->assertSame('America/Argentina/Cordoba', $result['timezone']);
        $this->assertSame('Europe/Madrid', $result['requested_timezone']);
        $this->assertSame(1500, $result['total_bytes']);
    }

    public function test_invalid_zone_does_not_modify_persisted_setting(): void
    {
        $this->actingAs($this->admin())->put('/configuracion/zona-horaria', ['timezone' => 'America/Bogota'])->assertRedirect();
        $this->putJson('/configuracion/zona-horaria', ['timezone' => 'GMT-5'])->assertUnprocessable();
        $this->assertDatabaseHas('display_settings', ['key' => 'timezone', 'value' => 'America/Bogota']);
    }
}
