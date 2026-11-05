<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StabilityV51Test extends TestCase
{
    use RefreshDatabase;

    private function device(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC estabilidad', 'hostname' => 'PC-TEST',
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', $token),
        ]);
        return [$device, $token];
    }

    public function test_lost_command_delivery_is_reoffered_with_same_uuid_after_lease(): void
    {
        [$device, $token] = $this->device();
        $command = DeviceCommand::query()->create([
            'uuid' => (string) Str::uuid(), 'device_id' => $device->id,
            'action' => 'restart', 'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);
        $endpoint = '/api/v1/agents/commands/next';
        $this->withToken($token)->postJson($endpoint)->assertOk()->assertJsonPath('command.id', $command->uuid);
        $this->withToken($token)->postJson($endpoint)->assertOk()->assertJsonPath('command', null);
        $this->travel(35)->seconds();
        // The server resends an ID, not a new command. Local receipts prevent a second execution.
        $this->withToken($token)->postJson($endpoint)->assertOk()->assertJsonPath('command.id', $command->uuid);
        $this->assertSame('dispatched', $command->fresh()->status);
        $result = '/api/v1/agents/commands/'.$command->uuid.'/result';
        $this->withToken($token)->postJson($result, ['status' => 'completed', 'message' => 'ok'])->assertOk();
        $this->withToken($token)->postJson($result, ['status' => 'completed', 'message' => 'ok'])->assertOk();
        $this->assertSame('completed', $command->fresh()->status);
        $this->withToken($token)->postJson($endpoint)->assertJsonPath('command', null);
    }

    public function test_expired_dispatched_command_becomes_unknown_but_late_receipt_can_resolve_it(): void
    {
        [$device, $token] = $this->device();
        $command = DeviceCommand::query()->create([
            'uuid' => (string) Str::uuid(), 'device_id' => $device->id,
            'action' => 'shutdown', 'status' => 'dispatched',
            'dispatched_at' => now()->subMinutes(3), 'expires_at' => now()->subMinute(),
        ]);
        $this->withToken($token)->postJson('/api/v1/agents/commands/next')
            ->assertOk()->assertJsonPath('command', null);
        $this->assertSame('unknown', $command->fresh()->status);
        $this->withToken($token)->postJson('/api/v1/agents/commands/'.$command->uuid.'/result', [
            'status' => 'completed', 'message' => 'Apagado programado.',
        ])->assertOk();
        $this->assertSame('completed', $command->fresh()->status);
    }

    public function test_invalid_inventory_reports_field_name_and_safe_payload(): void
    {
        [$device, $token] = $this->device();
        $this->withToken($token)->postJson('/api/v1/agents/inventory', [
            'captured_at' => now()->toIso8601String(), 'processor' => ['max_mhz' => -2],
            'volumes' => [], 'network' => ['interfaces' => [], 'dns_servers' => []],
        ])->assertStatus(422)->assertJsonValidationErrors('processor.max_mhz');
    }

    public function test_five_second_metrics_are_accepted_but_heartbeat_still_has_its_own_floor(): void
    {
        [$device, $token] = $this->device();
        \App\Models\SystemSetting::query()->updateOrCreate(['key' => 'metrics_interval_seconds'], ['value' => 5]);
        \App\Models\SystemSetting::query()->updateOrCreate(['key' => 'heartbeat_interval_seconds'], ['value' => 10]);
        $this->withToken($token)->postJson('/api/v1/agents/heartbeat', [
            'hostname' => 'PC-TEST', 'platform' => 'Windows 11',
        ])->assertOk()->assertJsonPath('settings.metrics_interval_seconds', 5)
          ->assertJsonPath('settings.heartbeat_interval_seconds', 20);
    }
}
