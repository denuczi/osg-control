<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DevicePairingRequest;
use App\Models\DeviceReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevicePairingAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create(['name' => 'Administrador', 'email' => 'admin@example.test',
            'password' => 'password123456', 'is_admin' => true]);
    }

    private function requestPairing(): array
    {
        $uuid = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $response = $this->postJson('/api/v1/agents/pairing', [
            'uuid' => $uuid, 'token' => $secret,
            'hostname' => 'LAB-10', 'platform' => 'Windows 11 Pro',
        ])->assertCreated()->json();
        return [$uuid, $secret, $response];
    }

    private function device(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Equipo de prueba',
            'hostname' => 'LAB-10', 'platform' => 'Windows 10',
            'token_hash' => hash('sha256', $token),
        ]);
        return [$device, $token];
    }

    public function test_pairing_requires_admin_approval_and_proves_agent_secret(): void
    {
        [$uuid, $secret, $response] = $this->requestPairing();
        $id = $response['request_id'];
        $this->assertSame(6, strlen($response['verification_code']));
        $this->assertDatabaseMissing('devices', ['uuid' => $uuid]);
        $this->getJson('/api/v1/agents/pairing/'.$id)->assertNotFound();
        $this->withToken(bin2hex(random_bytes(32)))->getJson('/api/v1/agents/pairing/'.$id)->assertNotFound();
        $this->withToken($secret)->getJson('/api/v1/agents/pairing/'.$id)->assertOk()->assertJsonPath('status', 'pending');
        $this->post('/equipos/vinculaciones/'.$id.'/aprobar', [
            'verification_code' => $response['verification_code'],
        ])->assertRedirect('/login');
        $this->actingAs($this->admin())->post('/equipos/vinculaciones/'.$id.'/aprobar', [
            'verification_code' => $response['verification_code'],
        ])->assertRedirect(route('devices.management'));
        $this->assertDatabaseHas('devices', ['uuid' => $uuid, 'token_hash' => hash('sha256', $secret)]);
        $this->withToken($secret)->getJson('/api/v1/agents/pairing/'.$id)->assertJsonPath('status', 'approved');
        $this->withToken($secret)->postJson('/api/v1/agents/heartbeat', [
            'hostname' => 'LAB-10', 'platform' => 'Windows 11 Pro',
        ])->assertOk();
        $this->assertSame(1, Device::query()->count());
    }

    public function test_wrong_code_is_limited_and_does_not_create_a_device(): void
    {
        [$uuid, $secret, $response] = $this->requestPairing();
        $bad = $response['verification_code'] === '000000' ? '000001' : '000000';
        $admin = $this->admin();
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)->from(route('devices.management'))
                ->post('/equipos/vinculaciones/'.$response['request_id'].'/aprobar', ['verification_code' => $bad])
                ->assertSessionHasErrors('verification_code');
        }
        $this->assertSame(3, DevicePairingRequest::query()->firstOrFail()->failed_attempts);
        $this->actingAs($admin)->from(route('devices.management'))
            ->post('/equipos/vinculaciones/'.$response['request_id'].'/aprobar',
                ['verification_code' => $response['verification_code']])->assertSessionHasErrors('verification_code');
        $this->assertDatabaseMissing('devices', ['uuid' => $uuid]);
    }

    public function test_expired_request_cannot_be_approved(): void
    {
        [$uuid, $secret, $response] = $this->requestPairing();
        DevicePairingRequest::query()->firstOrFail()->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->admin())->from(route('devices.management'))
            ->post('/equipos/vinculaciones/'.$response['request_id'].'/aprobar',
                ['verification_code' => $response['verification_code']])->assertSessionHasErrors('verification_code');
        $this->withToken($secret)->getJson('/api/v1/agents/pairing/'.$response['request_id'])
            ->assertJsonPath('status', 'expired');
    }

    public function test_panel_can_generate_a_single_use_enrollment_code(): void
    {
        $this->post('/equipos/codigos')->assertRedirect('/login');
        $this->actingAs($this->admin())->post('/equipos/codigos')
            ->assertRedirect(route('devices.management'))
            ->assertSessionHas('new_enrollment_code');
        $this->assertDatabaseCount('enrollment_codes', 1);
    }

    public function test_archive_and_restore_keep_the_agent_revoked(): void
    {
        [$device, $token] = $this->device();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/equipos/'.$device->uuid.'/archivar', ['confirm' => true])
            ->assertRedirect(route('devices.management'));
        $this->assertNotNull($device->fresh()->archived_at);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->withToken($token)->postJson('/api/v1/agents/heartbeat', [
            'hostname' => 'LAB-10', 'platform' => 'Windows 10',
        ])->assertUnauthorized();
        $this->actingAs($admin)->post('/equipos/'.$device->uuid.'/restaurar')
            ->assertRedirect(route('devices.management'));
        $this->assertNull($device->fresh()->archived_at);
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_deletion_requires_exact_name_and_retains_an_audit_trail(): void
    {
        [$device] = $this->device();
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('devices.management'))
            ->delete('/equipos/'.$device->uuid, ['confirm_name' => 'otro equipo'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('devices', ['uuid' => $device->uuid]);
        $this->actingAs($admin)->delete('/equipos/'.$device->uuid, ['confirm_name' => $device->name])
            ->assertRedirect(route('devices.management'));
        $this->assertDatabaseMissing('devices', ['uuid' => $device->uuid]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.deleted', 'device_id' => null]);
    }

    public function test_deletion_refuses_active_report_jobs(): void
    {
        [$device] = $this->device();
        $admin = $this->admin();
        DeviceReport::query()->create([
            'uuid' => (string) Str::uuid(), 'device_id' => $device->id,
            'user_id' => $admin->id, 'status' => 'processing',
            'ai_requested' => false, 'ai_status' => 'disabled',
            'period_from' => now()->subDays(7), 'period_to' => now(),
        ]);
        $this->actingAs($admin)->from(route('devices.management'))
            ->delete('/equipos/'.$device->uuid, ['confirm_name' => $device->name])
            ->assertSessionHasErrors('device');
        $this->assertDatabaseHas('devices', ['uuid' => $device->uuid]);
    }
}
