<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInventory;
use App\Models\DeviceMediaEvent;
use App\Models\MetricSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InfrastructureMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAgent(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC infraestructura',
            'hostname' => 'PC-LAB', 'platform' => 'Windows 11',
            'token_hash' => hash('sha256', $token),
        ]);
        return [$device, $token];
    }

    private function payload(): array
    {
        return [
            'captured_at' => now()->subMinute()->toIso8601String(),
            'processor' => ['name' => 'Intel Celeron', 'physical_cores' => 2,
                'logical_processors' => 2, 'max_mhz' => 2800, 'current_mhz' => 1200],
            'volumes' => [[
                'mountpoint' => 'C:\\', 'device' => '\\\\.\\PHYSICALDRIVE0',
                'filesystem' => 'NTFS', 'label' => 'Windows',
                'total_bytes' => 10000000, 'used_bytes' => 3000000, 'free_bytes' => 7000000,
                'used_percent' => 30, 'removable' => false, 'media_type' => 'SSD',
            ]],
            'network' => [
                'default_gateway' => '192.168.0.1', 'dns_servers' => ['192.168.0.1'],
                'ssid' => null, 'interfaces' => [[
                    'name' => 'Ethernet', 'up' => true, 'link_speed_mbps' => 100,
                    'mac' => '00:11:22:33:44:55',
                    'ipv4' => [['address' => '192.168.0.20', 'netmask' => '255.255.255.0']],
                    'ipv6' => [],
                ]],
            ],
        ];
    }

    public function test_inventory_requires_agent_and_does_not_revert_on_old_replay(): void
    {
        [$device, $token] = $this->registeredAgent();
        $this->postJson('/api/v1/agents/inventory', $this->payload())->assertUnauthorized();
        $fresh = $this->payload();
        $this->withToken($token)->postJson('/api/v1/agents/inventory', $fresh)->assertOk();
        $this->assertSame(2, DeviceInventory::query()->firstOrFail()->processor['physical_cores']);
        $old = $this->payload();
        $old['captured_at'] = now()->subHours(2)->toIso8601String();
        $old['processor']['physical_cores'] = 8;
        $this->withToken($token)->postJson('/api/v1/agents/inventory', $old)->assertOk();
        $this->assertSame(2, DeviceInventory::query()->firstOrFail()->processor['physical_cores']);
        $this->assertSame(1, DeviceInventory::query()->count());
    }

    public function test_inventory_accepts_empty_network_address_lists_and_volumes(): void
    {
        [$device, $token] = $this->registeredAgent();
        $payload = $this->payload();
        $payload['volumes'] = [];
        $payload['network']['dns_servers'] = [];
        $payload['network']['interfaces'][0]['ipv4'] = [];
        $payload['network']['interfaces'][0]['ipv6'] = [];

        $this->withToken($token)
            ->postJson('/api/v1/agents/inventory', $payload)
            ->assertOk();

        $stored = DeviceInventory::query()->where('device_id', $device->id)->firstOrFail();
        $this->assertSame([], $stored->volumes);
        $this->assertSame([], $stored->network['dns_servers']);
        $this->assertSame([], $stored->network['interfaces'][0]['ipv4']);
        $this->assertSame([], $stored->network['interfaces'][0]['ipv6']);
    }

    public function test_inventory_is_exposed_without_agent_token(): void
    {
        [$device, $token] = $this->registeredAgent();
        $this->withToken($token)->postJson('/api/v1/agents/inventory', $this->payload())->assertOk();
        $admin = User::query()->create(['name'=>'Admin','email'=>'admin@example.test',
            'password'=>'password123456','is_admin'=>true]);
        $this->actingAs($admin)->get('/equipos/'.$device->uuid.'/estado')
            ->assertOk()->assertJsonPath('inventory.processor.physical_cores', 2)
            ->assertJsonMissingPath('token_hash');
    }

    public function test_usb_events_are_idempotent_and_kept_separately_from_metrics(): void
    {
        [$device, $token] = $this->registeredAgent();
        $event = ['event_uuid'=>(string) Str::uuid(), 'observed_at'=>now()->subMinute()->toIso8601String(),
            'kind'=>'connected', 'volume'=>[
                'mountpoint'=>'E:\\','device'=>'USBSTOR', 'total_bytes'=>32000000000,
                'used_bytes'=>1000000000,'free_bytes'=>31000000000,'removable'=>true,
            ]];
        $payload = ['events'=>[$event]];
        $this->withToken($token)->postJson('/api/v1/agents/media-events/batch', $payload)
            ->assertOk()->assertJsonCount(1, 'accepted_ids');
        $this->withToken($token)->postJson('/api/v1/agents/media-events/batch', $payload)->assertOk();
        $this->assertSame(1, DeviceMediaEvent::query()->count());
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_batch_stores_network_bandwidth_and_memory_sizes(): void
    {
        [$device, $token] = $this->registeredAgent();
        $sample = [
            'sample_uuid'=>(string)Str::uuid(), 'captured_at'=>now()->subMinute()->toIso8601String(),
            'cpu_percent'=>23, 'memory_percent'=>50, 'disk_percent'=>70,
            'memory_total_bytes'=>8589934592, 'memory_used_bytes'=>4294967296,
            'memory_available_bytes'=>4294967296, 'disk_total_bytes'=>100000000000,
            'disk_used_bytes'=>70000000000, 'disk_free_bytes'=>30000000000,
            'network_rx_bps'=>8000000,'network_tx_bps'=>1000000,'processes'=>[],
        ];
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', ['samples'=>[$sample]])->assertOk();
        $this->assertSame(8000000, $device->fresh()->network_rx_bps);
        $this->assertSame(4294967296, $device->fresh()->memory_used_bytes);
        $this->assertSame(8000000, MetricSample::query()->firstOrFail()->network_rx_bps);
    }
}
