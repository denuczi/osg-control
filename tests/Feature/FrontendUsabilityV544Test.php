<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FrontendUsabilityV544Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Administración', 'email' => 'admin-v544@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
    }

    private function device(): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Notebook DELL', 'hostname' => 'DESKTOP-TEST',
            'platform' => 'Windows 10', 'token_hash' => hash('sha256', random_bytes(32)),
            'last_seen_at' => now(),
        ]);
    }

    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) (app(HandleInertiaRequests::class)->version(request()) ?? ''),
        ];
    }

    public function test_activity_exposes_human_readable_action_contract_and_original_message(): void
    {
        $admin = $this->admin();
        $device = $this->device();
        $uuid = (string) Str::uuid();
        DeviceCommand::query()->create([
            'uuid' => $uuid, 'device_id' => $device->id, 'user_id' => $admin->id,
            'action' => 'show_message', 'status' => 'completed',
            'parameters' => ['message' => "Aviso para el usuario\nSegunda línea"],
            'result_message' => 'La ventana se cerró mediante Cerrar.',
            'expires_at' => now()->addMinutes(5),
        ]);
        $this->travelTo(now()->subMinutes(2));
        AuditLog::query()->create([
            'user_id' => $admin->id, 'device_id' => $device->id,
            'event' => 'command.created',
            'context' => ['command_uuid' => $uuid, 'action' => 'show_message'],
        ]);
        $this->travelBack();
        AuditLog::query()->create([
            'user_id' => $admin->id, 'device_id' => $device->id,
            'event' => 'command.completed',
            'context' => ['command_uuid' => $uuid, 'action' => 'show_message', 'message' => 'La ventana se cerró mediante Cerrar.'],
        ]);
        $this->actingAs($admin)->withHeaders($this->inertiaHeaders())->get('/actividad')
            ->assertOk()->assertJsonPath('component', 'Activity/Index')
            ->assertJsonPath('props.logs.data.0.action', 'show_message')
            ->assertJsonPath('props.logs.data.0.message_text', "Aviso para el usuario\nSegunda línea")
            ->assertJsonPath('props.logs.data.0.result_message', 'La ventana se cerró mediante Cerrar.')
            ->assertJsonPath('props.logs.data.1.message_text', "Aviso para el usuario\nSegunda línea");
    }

    public function test_device_command_projection_includes_message_but_not_credentials(): void
    {
        $admin = $this->admin();
        $device = $this->device();
        $command = DeviceCommand::query()->create([
            'uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'user_id' => $admin->id,
            'action' => 'show_message', 'status' => 'pending', 'parameters' => ['message' => 'Hola desde el panel'],
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->actingAs($admin)->withHeaders($this->inertiaHeaders())
            ->get('/equipos/'.$device->uuid)
            ->assertOk()->assertJsonPath('props.commands.0.uuid', $command->uuid)
            ->assertJsonPath('props.commands.0.message_text', 'Hola desde el panel')
            ->assertJsonMissingPath('props.device.token_hash');
    }

    public function test_guest_cannot_read_activity_message_history(): void
    {
        $this->get('/actividad')->assertRedirect('/login');
    }
}
