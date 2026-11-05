<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceSystemEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WindowsEventFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_events_by_id_channel_and_message_without_leaking_to_guests(): void
    {
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Laboratorio', 'hostname' => 'LAB-10',
            'platform' => 'Windows 10', 'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
        ]);
        foreach ([['id' => 41, 'provider' => 'Kernel-Power', 'message' => 'Reinicio no limpio'],
                  ['id' => 1074, 'provider' => 'User32', 'message' => 'Reinicio solicitado por usuario']] as $event) {
            DeviceSystemEvent::query()->create([
                'device_id' => $device->id, 'event_uuid' => (string) Str::uuid(), 'channel' => 'System',
                'record_id' => random_int(100, 100000), 'event_id' => $event['id'],
                'provider' => $event['provider'], 'level' => 2, 'observed_at' => now(),
                'received_at' => now(), 'message' => $event['message'],
            ]);
        }
        $url = '/equipos/'.$device->uuid.'/diagnostico?event_id=1074&event_channel=System&event_q=usuario';
        $this->get($url)->assertRedirect('/login');
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test',
            'password' => 'password123456', 'is_admin' => true]);
        $this->actingAs($admin)->get($url)->assertOk();
        $this->actingAs($admin)->get('/equipos/'.$device->uuid.'/diagnostico?event_level=20')
            ->assertSessionHasErrors('event_level');
    }
}
