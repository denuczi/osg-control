<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MetricSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessInventoryV54Test extends TestCase
{
    use RefreshDatabase;

    private function registeredDevice(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC de prueba',
            'hostname' => 'PC-TEST', 'platform' => 'Windows 10',
            'token_hash' => hash('sha256', $token), 'last_seen_at' => now(),
        ]);
        return [$device, $token];
    }

    private function metric(string $time, int $cpu = 20): array
    {
        return [
            'sample_uuid' => (string) Str::uuid(), 'captured_at' => $time,
            'cpu_percent' => $cpu, 'memory_percent' => 40, 'disk_percent' => 20,
            'memory_total_bytes' => 4_194_496_512,
        ];
    }

    private function editor(): User
    {
        return User::query()->create([
            'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
    }

    public function test_process_snapshot_is_saved_even_when_last_metric_in_batch_is_lightweight(): void
    {
        [$device, $token] = $this->registeredDevice();
        $first = $this->metric(now()->subSeconds(65)->toIso8601String());
        $first['processes'] = [[
            'pid' => 345, 'name' => 'notepad.exe', 'memory_mb' => 15.5,
            'started_at' => 1781000000.4, 'can_terminate' => true,
        ]];
        $latest = $this->metric(now()->subSeconds(10)->toIso8601String(), 90);

        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$first, $latest],
        ])->assertOk()->assertJsonCount(2, 'accepted_ids');

        $device->refresh();
        $this->assertSame('notepad.exe', $device->processes[0]['name']);
        $this->assertNotNull($device->processes_sampled_at);
        $this->assertEquals(90, $device->cpu_percent);
        $this->actingAs($this->editor())->getJson('/equipos/'.$device->uuid.'/estado/ligero')
            ->assertOk()->assertJsonPath('processes.0.pid', 345);
    }

    public function test_old_replayed_process_snapshot_never_replaces_newer_snapshot(): void
    {
        [$device, $token] = $this->registeredDevice();
        $recent = $this->metric(now()->subSeconds(15)->toIso8601String());
        $recent['processes'] = [[
            'pid' => 12, 'name' => 'notepad.exe', 'memory_mb' => 10,
            'started_at' => 1781000000.4, 'can_terminate' => true,
        ]];
        $earlier = $this->metric(now()->subMinutes(2)->toIso8601String());
        $earlier['processes'] = [];
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', ['samples' => [$recent]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', ['samples' => [$earlier]])->assertOk();
        $this->assertCount(1, $device->fresh()->processes);
    }

    public function test_windows_idle_pid_zero_is_accepted_but_not_persisted_in_batch(): void
    {
        [$device, $token] = $this->registeredDevice();
        $sample = $this->metric(now()->subSeconds(10)->toIso8601String());
        $sample['processes'] = [
            ['pid' => 0, 'name' => 'System Idle Process', 'memory_mb' => 0,
                'started_at' => 0, 'can_terminate' => false],
            ['pid' => 4321, 'name' => 'notepad.exe', 'memory_mb' => 14,
                'started_at' => 1781000000.4, 'can_terminate' => true],
        ];
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$sample],
        ])->assertOk()->assertJsonPath('accepted_ids.0', $sample['sample_uuid']);

        $device->refresh();
        $this->assertSame([4321], array_column($device->processes, 'pid'));
        $this->assertNotNull($device->processes_sampled_at);
        $this->assertSame(1, MetricSample::query()->count());

        // A replayed offline sample is idempotent and is acknowledged.
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$sample],
        ])->assertOk()->assertJsonPath('accepted_ids.0', $sample['sample_uuid']);
        $this->assertSame(1, MetricSample::query()->count());
    }

    public function test_windows_idle_pid_zero_is_accepted_but_not_persisted_in_legacy_heartbeat(): void
    {
        [$device, $token] = $this->registeredDevice();
        $this->withToken($token)->postJson('/api/v1/agents/heartbeat', [
            'hostname' => 'PC-TEST', 'platform' => 'Windows 10',
            'cpu_percent' => 10, 'memory_percent' => 20, 'disk_percent' => 30,
            'memory_total_bytes' => 4_194_496_512,
            'processes' => [
                ['pid' => 0, 'name' => 'System Idle Process', 'memory_mb' => 0,
                    'started_at' => 0, 'can_terminate' => false],
                ['pid' => 4321, 'name' => 'notepad.exe', 'memory_mb' => 14,
                    'started_at' => 1781000000.4, 'can_terminate' => true],
            ],
        ])->assertOk();
        $this->assertSame([4321], array_column($device->fresh()->processes, 'pid'));
    }

    public function test_negative_pid_is_still_rejected(): void
    {
        [$device, $token] = $this->registeredDevice();
        $sample = $this->metric(now()->toIso8601String());
        $sample['processes'] = [[
            'pid' => -1, 'name' => 'invalid.exe', 'memory_mb' => 0,
            'started_at' => 0, 'can_terminate' => false,
        ]];
        $this->withToken($token)->postJson('/api/v1/agents/metrics/batch', [
            'samples' => [$sample],
        ])->assertUnprocessable()->assertJsonValidationErrors('samples.0.processes.0.pid');
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_stale_processes_are_not_shown_or_used_for_new_termination(): void
    {
        [$device] = $this->registeredDevice();
        $device->update([
            'processes' => [[
                'pid' => 123, 'name' => 'notepad.exe', 'memory_mb' => 10,
                'started_at' => 1781000000.4, 'can_terminate' => true,
            ]],
            'processes_sampled_at' => now()->subMinutes(10),
        ]);
        $admin = $this->editor();
        $this->actingAs($admin)->getJson('/equipos/'.$device->uuid.'/estado/ligero')
            ->assertOk()->assertJsonCount(0, 'processes');
        $this->post('/equipos/'.$device->uuid.'/ordenes', [
            'action' => 'terminate_process', 'pid' => 123, 'process_name' => 'notepad.exe',
            'process_started_at' => 1781000000.4, 'confirm' => true,
        ])->assertSessionHasErrors('action');
    }

    public function test_message_with_newline_is_enqueued_and_page_stays_available(): void
    {
        [$device] = $this->registeredDevice();
        $this->actingAs($this->editor())->post('/equipos/'.$device->uuid.'/ordenes', [
            'action' => 'show_message', 'message' => "Prueba de mensaje\nSegunda línea",
            'confirm' => true,
        ])->assertRedirect();
        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id, 'action' => 'show_message', 'status' => 'pending',
        ]);
        $this->get('/equipos/'.$device->uuid)->assertOk();
    }
}
