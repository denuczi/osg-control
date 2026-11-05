<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC Laboratorio',
            'hostname' => 'LAB-PC', 'platform' => 'Windows 11',
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'last_seen_at' => now(), 'processes' => [['pid' => 512, 'name' => 'notepad.exe', 'memory_mb' => 10, 'started_at' => 1781000000.4, 'can_terminate' => true]],
        ]);
    }

    public function test_demo_device_cannot_receive_remote_commands(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123456', 'is_admin' => true]);
        $device = $this->device();
        $device->update(['is_demo' => true]);
        $this->actingAs($user)->post('/equipos/'.$device->uuid.'/ordenes', ['action' => 'shutdown', 'confirm' => 1])
            ->assertSessionHasErrors('action');
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get('/panel')->assertRedirect('/login');
    }

    public function test_regular_authenticated_user_cannot_administer_devices(): void
    {
        $user = User::query()->create(['name' => 'Usuario', 'email' => 'user@example.test', 'password' => 'password123456', 'is_admin' => false]);
        $this->actingAs($user)->get('/equipos')->assertForbidden();
    }

    public function test_admin_can_view_device_and_enqueue_valid_shutdown(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123456', 'is_admin' => true]);
        $device = $this->device();
        $this->actingAs($user)->get('/equipos/'.$device->uuid)->assertOk()->assertSee('PC Laboratorio');
        $this->actingAs($user)->post('/equipos/'.$device->uuid.'/ordenes', ['action' => 'shutdown', 'confirm' => 1])->assertRedirect();
        $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'action' => 'shutdown', 'status' => 'pending']);
    }

    public function test_command_requires_confirmation_and_rejects_arbitrary_action(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123456', 'is_admin' => true]);
        $device = $this->device();
        $url = '/equipos/'.$device->uuid.'/ordenes';
        $this->actingAs($user)->post($url, ['action' => 'shutdown'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post($url, ['action' => 'execute_shell', 'confirm' => 1])->assertSessionHasErrors('action');
        $this->assertEquals(0, DeviceCommand::query()->count());
    }

    public function test_closing_process_requires_matching_start_time(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123456', 'is_admin' => true]);
        $device = $this->device();
        $url = '/equipos/'.$device->uuid.'/ordenes';
        $this->actingAs($user)->post($url, ['action' => 'terminate_process', 'pid' => 512, 'process_name' => 'notepad.exe', 'process_started_at' => 1781000001, 'confirm' => 1])->assertSessionHasErrors('action');
        $this->actingAs($user)->post($url, ['action' => 'terminate_process', 'pid' => 512, 'process_name' => 'notepad.exe', 'process_started_at' => 1781000000.4, 'confirm' => 1])->assertRedirect();
        $this->assertEquals(1, DeviceCommand::query()->count());
    }
    public function test_regular_user_cannot_request_process_termination(): void
    {
        $user = User::query()->create([
            'name' => 'Usuario', 'email' => 'user2@example.test',
            'password' => 'password123456', 'is_admin' => false,
        ]);
        $device = $this->device();
        $this->actingAs($user)->post('/equipos/'.$device->uuid.'/ordenes', [
            'action' => 'terminate_process', 'pid' => 512,
            'process_name' => 'notepad.exe', 'process_started_at' => 1781000000.4,
            'confirm' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_admin_can_request_termination_when_agent_cannot_precalculate_permissions(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin2@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
        $device = $this->device();
        // Existing agents can report false for every process when their
        // Windows session preflight fails. It is not an authorization source.
        $processes = $device->processes;
        $processes[0]['can_terminate'] = false;
        $device->update(['processes' => $processes, 'processes_sampled_at' => now()]);
        $url = '/equipos/'.$device->uuid.'/ordenes';
        $payload = [
            'action' => 'terminate_process', 'pid' => 512,
            'process_name' => 'notepad.exe', 'process_started_at' => 1781000000.4,
            'confirm' => 1,
        ];
        $this->actingAs($admin)->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id, 'action' => 'terminate_process', 'status' => 'pending',
        ]);
        $this->actingAs($admin)->post($url, array_merge($payload, ['process_started_at' => 1781000001]))
            ->assertSessionHasErrors('action');
        $this->assertDatabaseCount('device_commands', 1);
    }

}
