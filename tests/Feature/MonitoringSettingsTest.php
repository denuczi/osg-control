<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MetricSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitoringSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function deviceWithToken(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC test', 'hostname' => 'PC-TEST',
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', $token),
        ]);
        return [$device, $token];
    }

    public function test_admin_can_update_settings_and_agent_receives_them(): void
    {
        [$device, $token] = $this->deviceWithToken();
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin-'.Str::random(8).'@example.test', 'password' => 'password123456', 'is_admin' => true]);
        $this->actingAs($user)->put('/configuracion', [
            'metrics_interval_seconds' => 5, 'dashboard_refresh_seconds' => 5,
            'heartbeat_interval_seconds' => 30, 'command_poll_seconds' => 5,
            'retention_days' => 14,
        ])->assertRedirect();
        $this->withToken($token)->postJson('/api/v1/agents/heartbeat', [
            'hostname' => 'PC-TEST', 'platform' => 'Windows 11',
        ])->assertOk()->assertJsonPath('settings.metrics_interval_seconds', 5)
          ->assertJsonPath('settings.command_poll_seconds', 5);
        $this->assertDatabaseHas('system_settings', ['key' => 'metrics_interval_seconds', 'value' => 5]);
        $this->assertDatabaseHas('system_settings', ['key' => 'dashboard_refresh_seconds', 'value' => 5]);
    }

    public function test_settings_reject_out_of_range_values(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin-'.Str::random(8).'@example.test', 'password' => 'password123456', 'is_admin' => true]);
        $this->actingAs($user)->put('/configuracion', [
            'metrics_interval_seconds' => 4, 'dashboard_refresh_seconds' => 10,
            'heartbeat_interval_seconds' => 20, 'command_poll_seconds' => 5,
            'retention_days' => 7,
        ])->assertSessionHasErrors('metrics_interval_seconds');
    }

    public function test_one_hour_chart_keeps_short_interval_samples_distinct(): void
    {
        [$device] = $this->deviceWithToken();
        $user = User::query()->create([
            'name' => 'Admin', 'email' => 'chart-'.Str::random(8).'@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-05 12:00:00', 'UTC'));
        try {
            foreach ([36, 24, 12] as $secondsAgo) {
                MetricSample::query()->create([
                    'device_id' => $device->id,
                    'sample_uuid' => (string) Str::uuid(),
                    'recorded_at' => now()->subSeconds($secondsAgo),
                    'received_at' => now(),
                    'cpu_percent' => $secondsAgo,
                    'memory_percent' => 50,
                    'disk_percent' => 30,
                ]);
            }

            // Three readings in one minute must not collapse into one point.
            $this->actingAs($user)->getJson('/equipos/'.$device->uuid.'/metricas?hours=1')
                ->assertOk()->assertJsonCount(3, 'samples');
            // Long-range charts remain bounded by the original 60s+ buckets.
            $this->actingAs($user)->getJson('/equipos/'.$device->uuid.'/metricas?hours=6')
                ->assertOk()->assertJsonCount(1, 'samples');
        } finally {
            $this->travelBack();
        }
    }

    public function test_replayed_batch_is_idempotent_and_old_samples_do_not_replace_live_metrics(): void
    {
        [$device, $token] = $this->deviceWithToken();
        $old = now()->subMinutes(3)->toIso8601String();
        $new = now()->subMinutes(1)->toIso8601String();
        $sample = fn ($time, $cpu, $uuid) => [
            'sample_uuid' => $uuid, 'captured_at' => $time, 'cpu_percent' => $cpu,
            'memory_percent' => 40, 'disk_percent' => 30,
            'memory_total_bytes' => 8000000000, 'processes' => [],
        ];
        $earlier = $sample($old, 15, (string) Str::uuid());
        $recent = $sample($new, 90, (string) Str::uuid());
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$recent],
        ])->assertOk()->assertJsonCount(1, 'accepted_ids');
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$earlier, $recent],
        ])->assertOk()->assertJsonCount(2, 'accepted_ids');
        $this->assertSame(2, MetricSample::query()->count());
        $this->assertEquals(90, $device->fresh()->cpu_percent);
    }

    public function test_agent_cannot_submit_invalid_future_or_oversized_batch(): void
    {
        [$device, $token] = $this->deviceWithToken();
        $data = [
            'sample_uuid' => (string) Str::uuid(),
            'captured_at' => now()->addDay()->toIso8601String(),
            'cpu_percent' => 20, 'memory_percent' => 30, 'disk_percent' => 40,
            'memory_total_bytes' => 100, 'processes' => [],
        ];
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', ['samples' => [$data]])
            ->assertUnprocessable();
        $this->assertSame(0, MetricSample::query()->count());
    }
}
