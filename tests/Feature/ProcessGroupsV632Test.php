<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessGroupsV632Test extends TestCase
{
    use RefreshDatabase;

    private function device(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Equipo de prueba', 'hostname' => 'TEST-PC',
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', $token), 'last_seen_at' => now(),
        ]);
        return [$device, $token];
    }

    private function sample(array $overrides = []): array
    {
        return array_replace([
            'sample_uuid' => (string) Str::uuid(), 'captured_at' => now()->subSeconds(15)->toIso8601String(),
            'cpu_percent' => 12, 'memory_percent' => 30, 'disk_percent' => 40,
            'memory_total_bytes' => 8589934592,
        ], $overrides);
    }

    public function test_metadata_and_partial_coverage_survive_batch_and_ui_projection(): void
    {
        [$device, $token] = $this->device();
        $key = str_repeat('a', 32);
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$this->sample([
                'processes_total_count' => 3,
                'processes' => [
                    ['pid' => 101, 'name' => 'app.exe', 'display_name' => 'Editor de prueba', 'group_key' => $key,
                        'memory_mb' => 40, 'started_at' => 1781000000.0, 'can_terminate' => true],
                    ['pid' => 102, 'name' => 'app.exe', 'display_name' => 'Editor de prueba', 'group_key' => $key,
                        'memory_mb' => 60, 'started_at' => 1781000001.0, 'can_terminate' => true],
                ],
            ])],
        ])->assertOk();

        $this->assertSame(3, $device->fresh()->processes_total_count);
        $this->assertSame('Editor de prueba', $device->fresh()->processes[0]['display_name']);
        $this->assertSame($key, $device->fresh()->processes[1]['group_key']);

        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'processgroup@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
        $this->actingAs($admin)->getJson('/equipos/'.$device->uuid.'/estado/ligero')
            ->assertOk()->assertJsonPath('processes_total_count', 3)
            ->assertJsonPath('processes.0.display_name', 'Editor de prueba')
            ->assertJsonPath('processes.1.group_key', $key);
    }

    public function test_old_agents_without_metadata_remain_compatible(): void
    {
        [$device, $token] = $this->device();
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$this->sample(['processes' => [
                ['pid' => 200, 'name' => 'old.exe', 'memory_mb' => 15,
                    'started_at' => 1781000002.0, 'can_terminate' => false],
            ]])],
        ])->assertOk();
        $this->assertNull($device->fresh()->processes_total_count);
        $this->assertArrayNotHasKey('group_key', $device->fresh()->processes[0]);
    }

    public function test_older_replay_cannot_overwrite_newer_process_count_or_names(): void
    {
        [$device, $token] = $this->device();
        $recent = $this->sample(['processes_total_count' => 25,
            'processes' => [['pid' => 300, 'name' => 'new.exe', 'memory_mb' => 13,
                'started_at' => 1781000003.0, 'can_terminate' => false]],
        ]);
        $older = $this->sample(['captured_at' => now()->subMinutes(2)->toIso8601String(),
            'processes_total_count' => 50,
            'processes' => [['pid' => 301, 'name' => 'old.exe', 'memory_mb' => 10,
                'started_at' => 1781000004.0, 'can_terminate' => false]],
        ]);
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', ['samples' => [$recent]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', ['samples' => [$older]])->assertOk();
        $this->assertSame(25, $device->fresh()->processes_total_count);
        $this->assertSame('new.exe', $device->fresh()->processes[0]['name']);
    }


    public function test_offline_or_stale_capture_does_not_expose_a_current_total(): void
    {
        [$device] = $this->device();
        $device->update([
            'processes_total_count' => 19,
            'processes' => [['pid' => 800, 'name' => 'old.exe', 'memory_mb' => 20,
                'started_at' => 1781000003.0, 'can_terminate' => false]],
            'processes_sampled_at' => now()->subMinutes(10),
        ]);
        $view = \App\Support\DeviceViewData::from($device->fresh(), true);
        $this->assertNull($view['processes_total_count']);
        $this->assertSame([], $view['processes']);
    }

    public function test_invalid_group_identity_is_rejected(): void
    {
        [$device, $token] = $this->device();
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$this->sample(['processes' => [[
                'pid' => 300, 'name' => 'app.exe', 'memory_mb' => 10,
                'started_at' => 1781000003.0, 'can_terminate' => false,
                'group_key' => '../invalid',
            ]]])],
        ])->assertUnprocessable()->assertJsonValidationErrors('samples.0.processes.0.group_key');
    }
}
