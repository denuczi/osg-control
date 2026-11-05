<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\EnrollmentCode;
use App\Models\MetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(string $code, string $uuid): array
    {
        return $this->postJson('/api/v1/agents/enroll', [
            'code' => $code, 'uuid' => $uuid,
            'hostname' => 'LAB-PC01', 'platform' => 'Windows 11',
        ])->assertCreated()->json();
    }

    public function test_enrollment_consumes_code_once_and_issues_individual_token(): void
    {
        $code = bin2hex(random_bytes(24));
        EnrollmentCode::query()->create(['code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(15)]);
        $uuid = (string) Str::uuid();
        $response = $this->enroll($code, $uuid);
        $this->assertEquals($uuid, $response['device_id']);
        $this->assertEquals(64, strlen($response['token']));
        $this->assertDatabaseHas('devices', ['uuid' => $uuid, 'token_hash' => hash('sha256', $response['token'])]);
        $this->postJson('/api/v1/agents/enroll', [
            'code' => $code, 'uuid' => (string) Str::uuid(),
            'hostname' => 'PC-2', 'platform' => 'Windows 11',
        ])->assertStatus(422);
    }

    public function test_heartbeat_requires_bearer_and_records_valid_metrics(): void
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Laboratorio', 'hostname' => 'LAB-PC01',
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', $token),
        ]);
        $payload = [
            'hostname' => 'LAB-PC01', 'platform' => 'Windows 11', 'cpu_percent' => 27,
            'memory_percent' => 61, 'disk_percent' => 43, 'memory_total_bytes' => 8589934592,
            'processes' => [['pid' => 1234, 'name' => 'notepad.exe', 'memory_mb' => 30.5, 'started_at' => 1781000000.4, 'can_terminate' => true]],
        ];
        $this->postJson('/api/v1/agents/heartbeat', $payload)->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/agents/heartbeat', $payload)->assertOk();
        $this->assertEquals(27, $device->fresh()->cpu_percent);
        $this->assertEquals(1, MetricSample::query()->count());
        $this->withToken($token)->postJson('/api/v1/agents/heartbeat', $payload)->assertOk();
        $this->assertEquals(1, MetricSample::query()->count());
    }

    public function test_agent_cannot_acknowledge_a_command_belonging_to_another_device(): void
    {
        $tokenA = bin2hex(random_bytes(32));
        $tokenB = bin2hex(random_bytes(32));
        $a = Device::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'PC A', 'hostname' => 'A', 'platform' => 'Windows', 'token_hash' => hash('sha256', $tokenA)]);
        $b = Device::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'PC B', 'hostname' => 'B', 'platform' => 'Windows', 'token_hash' => hash('sha256', $tokenB)]);
        $cmd = DeviceCommand::query()->create(['uuid' => (string) Str::uuid(), 'device_id' => $b->id, 'action' => 'restart', 'status' => 'dispatched']);
        $this->withToken($tokenA)->postJson('/api/v1/agents/commands/'.$cmd->uuid.'/result', ['status' => 'completed', 'message' => 'ok'])->assertNotFound();
        $this->assertEquals('dispatched', $cmd->fresh()->status);
    }

    public function test_revoked_agent_is_rejected(): void
    {
        $token = bin2hex(random_bytes(32));
        Device::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'PC', 'hostname' => 'PC', 'platform' => 'Windows', 'token_hash' => hash('sha256', $token), 'revoked_at' => now()]);
        $this->withToken($token)->postJson('/api/v1/agents/commands/next')->assertUnauthorized();
    }
}
