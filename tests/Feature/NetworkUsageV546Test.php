<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NetworkUsageV546Test extends TestCase
{
    use RefreshDatabase;

    private function agent(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC consumo',
            'hostname' => 'PC-LAB', 'platform' => 'Windows 10',
            'token_hash' => hash('sha256', $token),
        ]);
        return [$device, $token];
    }

    private function bucket(int $rx = 1000, int $tx = 500, int $revision = 1, ?string $uuid = null): array
    {
        return [
            'bucket_uuid' => $uuid ?? (string) Str::uuid(),
            'interface_name' => 'Ethernet', 'boot_id' => '1984567890',
            'hour_utc' => CarbonImmutable::now('UTC')->startOfHour()->subHour()->toIso8601String(),
            'rx_bytes' => $rx, 'tx_bytes' => $tx,
            'observed_seconds' => min(3600, $revision * 30), 'revision' => $revision,
        ];
    }

    public function test_only_the_enrolled_agent_may_upload_usage(): void
    {
        [$device, $token] = $this->agent();
        $payload = ['buckets' => [$this->bucket()]];
        $this->postJson('/api/v1/agents/network-usage/batch', $payload)->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', $payload)
            ->assertOk()->assertJsonCount(1, 'accepted');
        $this->assertSame(1, DB::table('network_usage_buckets')->count());
    }

    public function test_revisions_are_idempotent_and_increment_only_the_difference(): void
    {
        [$device, $token] = $this->agent();
        $first = $this->bucket(1000, 500);
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [$first]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [$first]])->assertOk();
        $next = array_replace($first, ['rx_bytes' => 2500, 'tx_bytes' => 800, 'observed_seconds' => 60, 'revision' => 2]);
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [$next]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [$first]])->assertOk();
        $this->assertSame(2500, (int) DB::table('network_usage_daily')->sum('rx_bytes'));
        $this->assertSame(800, (int) DB::table('network_usage_daily')->sum('tx_bytes'));
        $this->assertSame(2500, (int) DB::table('network_usage_hourly')->sum('rx_bytes'));
        $this->assertSame(1, DB::table('network_usage_buckets')->count());
        $this->assertSame(2, (int) DB::table('network_usage_buckets')->value('revision'));
    }

    public function test_same_revision_with_different_totals_and_regression_are_rejected(): void
    {
        [, $token] = $this->agent();
        $first = $this->bucket();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [$first]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [[...$first, 'rx_bytes' => 2000]]])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [[...$first, 'revision' => 2, 'rx_bytes' => 999]]])->assertUnprocessable();
        $this->assertSame(1000, (int) DB::table('network_usage_daily')->sum('rx_bytes'));
    }

    public function test_a_malformed_second_bucket_rolls_back_the_first(): void
    {
        [, $token] = $this->agent();
        $a = $this->bucket();
        $b = $this->bucket(100, 100, 1);
        $b['hour_utc'] = CarbonImmutable::now('UTC')->addDay()->toIso8601String();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [$a, $b]])->assertUnprocessable();
        $this->assertSame(0, DB::table('network_usage_buckets')->count());
        $this->assertSame(0, DB::table('network_usage_daily')->count());
    }

    public function test_admin_can_query_period_and_non_admin_cannot(): void
    {
        [$device, $token] = $this->agent();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch', ['buckets' => [$this->bucket()]])->assertOk();
        $this->getJson('/consumo-red/datos?period=30d')->assertUnauthorized();
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin-consumo@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
        $this->actingAs($admin)->getJson('/consumo-red/datos?period=30d')->assertOk()
            ->assertJsonPath('total_bytes', 1500)
            ->assertJsonPath('measured_devices', 1)
            ->assertJsonPath('average_per_measured_device_bytes', 1500)
            ->assertJsonPath('measurement', 'network_interface_traffic');
        $this->actingAs($admin)->getJson('/equipos/'.$device->uuid.'/consumo-red?period=30d')->assertOk()
            ->assertJsonPath('rx_bytes', 1000);
    }

    public function test_same_source_hour_cannot_be_registered_twice_with_another_uuid(): void
    {
        [, $token] = $this->agent();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch',
            ['buckets' => [$this->bucket(1000, 500)]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/agents/network-usage/batch',
            ['buckets' => [$this->bucket(1000, 500)]])->assertUnprocessable();
        $this->assertSame(1, DB::table('network_usage_buckets')->count());
    }

    public function test_fleet_average_uses_measured_devices_not_registered_devices(): void
    {
        [$first, $firstToken] = $this->agent();
        [$second, $secondToken] = $this->agent();
        [$unmeasured] = $this->agent();
        $this->withToken($firstToken)->postJson('/api/v1/agents/network-usage/batch',
            ['buckets' => [$this->bucket(1000, 0)]])->assertOk();
        $this->withToken($secondToken)->postJson('/api/v1/agents/network-usage/batch',
            ['buckets' => [$this->bucket(2000, 1000)]])->assertOk();
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin-promedio@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
        $this->actingAs($admin)->getJson('/consumo-red/datos?period=30d')->assertOk()
            ->assertJsonPath('total_bytes', 4000)
            ->assertJsonPath('registered_devices', 3)
            ->assertJsonPath('measured_devices', 2)
            ->assertJsonPath('average_per_measured_device_bytes', 2000);
    }

    public function test_empty_period_is_not_reported_as_zero_measured_devices(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin-sin-datos@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
        $this->actingAs($admin)->getJson('/consumo-red/datos?period=month')->assertOk()
            ->assertJsonPath('measured_devices', 0)
            ->assertJsonPath('average_per_measured_device_bytes', null)
            ->assertJsonPath('days_with_data', 0)
            ->assertJsonPath('total_bytes', null);
    }
}
