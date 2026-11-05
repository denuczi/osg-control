<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Device;
use App\Models\DeviceDiagnostic;
use App\Models\DeviceSystemEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiagnosticsV60Test extends TestCase
{
    use RefreshDatabase;

    private function agent(): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create(['uuid' => (string) Str::uuid(),
            'name' => 'Equipo de pruebas', 'hostname' => 'WIN-TEST',
            'platform' => 'Windows 10', 'token_hash' => hash('sha256', $token)]);
        return [$device, $token];
    }

    private function snapshot(): array
    {
        return [
            'captured_at' => now()->subMinute()->toIso8601String(),
            'hardware' => ['status' => 'ok', 'data' => [
                'Manufacturer' => 'Dell', 'Model' => 'Notebook',
                'BatteryPresent' => true, 'BatteryPercent' => 84,
                'MemorySlots' => 2, 'MemoryModules' => [
                    ['CapacityBytes' => 4294967296, 'SpeedMHz' => 2400,
                        'Manufacturer' => 'Kingston', 'PartNumber' => 'AB', 'Slot' => 'DIMM0'],
                ],
            ]],
            'disks' => ['status' => 'unavailable', 'data' => null],
            'security' => ['status' => 'ok', 'data' => [
                'DefenderMode' => 'Normal', 'DefenderRealtime' => true,
                'AntivirusProviders' => ['Microsoft Defender Antivirus'],
                'FirewallProfiles' => [['Name'=>'Domain', 'Enabled'=>true]],
            ]],
        ];
    }

    private function event(): array
    {
        return ['event_uuid' => (string) Str::uuid(), 'channel' => 'System',
            'record_id' => 230, 'event_id' => 41, 'provider' => 'Kernel-Power',
            'level' => 2, 'observed_at' => now()->subMinute()->toIso8601String()];
    }

    public function test_only_registered_agent_uploads_diagnostics_and_older_capture_does_not_revert(): void
    {
        [$device, $token] = $this->agent();
        $this->postJson('/api/v1/agents/diagnostics', $this->snapshot())->assertUnauthorized();
        $new = $this->snapshot();
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $new)->assertOk();
        $old = $new;
        $old['captured_at'] = now()->subHours(3)->toIso8601String();
        $old['hardware']['data']['Model'] = 'Modelo antiguo';
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $old)->assertOk();
        $this->assertSame('Notebook', DeviceDiagnostic::query()->firstOrFail()->hardware['data']['Model']);
        $this->assertSame(1, DeviceDiagnostic::query()->count());
    }

    public function test_missing_sensors_have_explicit_status_not_fake_zero(): void
    {
        [$device, $token] = $this->agent();
        $payload = $this->snapshot();
        foreach (['hardware','disks','security'] as $section) {
            $payload[$section] = ['status'=>'unavailable', 'data'=>null];
        }
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $payload)->assertOk();
        $stored = DeviceDiagnostic::query()->firstOrFail();
        $this->assertNull($stored->security['data']);
        $this->assertSame('unavailable', $stored->security['status']);
    }

    public function test_malformed_diagnostic_does_not_overwrite_previous_valid_capture(): void
    {
        [$device, $token] = $this->agent();
        $valid = $this->snapshot();
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $valid)->assertOk();
        $invalid = $this->snapshot();
        $invalid['hardware']['data']['BatteryPercent'] = 800;
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $invalid)->assertUnprocessable();
        $this->assertSame(84, DeviceDiagnostic::query()->firstOrFail()->hardware['data']['BatteryPercent']);
    }

    public function test_events_are_idempotent_isolated_and_transactional(): void
    {
        [$device, $token] = $this->agent();
        $other = $this->agent()[0];
        $event = $this->event();
        $this->postJson('/api/v1/agents/system-events/batch', ['events'=>[$event]])->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch', ['events'=>[$event]])
            ->assertOk()->assertJsonPath('accepted_ids.0', $event['event_uuid']);
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch', ['events'=>[$event]])->assertOk();
        $this->assertSame(1, DeviceSystemEvent::query()->count());
        $this->assertSame($device->id, DeviceSystemEvent::query()->firstOrFail()->device_id);
        $changed = $event; $changed['event_id'] = 6008;
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch',
            ['events'=>[$changed]])->assertUnprocessable();
        $bad = $this->event(); $bad['event_id'] = 888;
        $good = $this->event();
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch', ['events'=>[$good,$bad]])->assertUnprocessable();
        $this->assertSame(1, DeviceSystemEvent::query()->count());
    }

    public function test_agent_event_descriptions_are_persisted_without_executing_html(): void
    {
        [$device, $token] = $this->agent();
        $event = $this->event();
        $event['message'] = 'Reinicio solicitado por DOMINIO\\operador desde C:\\Windows\\System32\\shutdown.exe <script>alert(1)</script>';
        $event['user_sid'] = 'S-1-5-21-100';
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch', ['events' => [$event]])->assertOk();
        $stored = DeviceSystemEvent::query()->firstOrFail();
        $this->assertSame($event['message'], $stored->message);
        $this->assertSame('S-1-5-21-100', $stored->user_sid);
        // Idempotent retries do not duplicate data.
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch', ['events' => [$event]])->assertOk();
        $this->assertSame(1, DeviceSystemEvent::query()->count());
        $event['message'] = str_repeat('x', 1201);
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch', ['events' => [$event]])->assertUnprocessable();
    }

    public function test_windows_support_metadata_is_validated_and_persisted(): void
    {
        [$device, $token] = $this->agent();
        $payload = $this->snapshot();
        $payload['security']['data']['Windows'] = [
            'Caption' => 'Microsoft Windows 10 Pro', 'EditionId' => 'Professional',
            'DisplayVersion' => '22H2', 'Build' => 19045, 'UBR' => 7000,
            'EsuCommercialStatus' => 'not_detected', 'EsuLicensedYears' => [],
        ];
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $payload)->assertOk();
        $this->assertSame(19045, DeviceDiagnostic::query()->firstOrFail()->security['data']['Windows']['Build']);
        $payload['captured_at'] = now()->toIso8601String();
        $payload['security']['data']['Windows']['ProductKey'] = 'SHOULD_NOT_BE_ACCEPTED';
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $payload)->assertUnprocessable();
        $this->assertArrayNotHasKey('ProductKey', DeviceDiagnostic::query()->firstOrFail()->security['data']['Windows']);
        unset($payload['security']['data']['Windows']['ProductKey']);
        $payload['security']['data']['Windows']['EsuLicensedYears'] = [0, 8];
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $payload)->assertUnprocessable();
    }

    public function test_normal_shutdown_events_and_protection_query_status_are_accepted(): void
    {
        [$device, $token] = $this->agent();
        $snapshot = $this->snapshot();
        $snapshot['security']['data']['ProtectionQueryStatus'] = 'unavailable';
        $snapshot['security']['data']['Windows'] = [
            'Caption' => 'Windows 10', 'EditionId' => 'Professional', 'Build' => 19045,
            'EsuCommercialStatus' => 'unknown', 'EsuLicensedYears' => [],
        ];
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $snapshot)->assertOk();
        $this->assertSame('unavailable', DeviceDiagnostic::query()->firstOrFail()
            ->security['data']['ProtectionQueryStatus']);
        foreach ([13 => 'Microsoft-Windows-Kernel-General', 6006 => 'EventLog'] as $id => $provider) {
            $event = $this->event();
            $event['event_id'] = $id;
            $event['provider'] = $provider;
            $event['record_id'] = $id + 100;
            $this->withToken($token)->postJson('/api/v1/agents/system-events/batch',
                ['events' => [$event]])->assertOk();
        }
        $this->assertSame(2, DeviceSystemEvent::query()->count());
    }

    public function test_admin_page_does_not_expose_diagnostics_to_regular_users(): void
    {
        [$device, $token] = $this->agent();
        $this->withToken($token)->postJson('/api/v1/agents/diagnostics', $this->snapshot())->assertOk();
        $event = $this->event();
        $this->withToken($token)->postJson('/api/v1/agents/system-events/batch',
            ['events' => [$event]])->assertOk();
        $url = '/equipos/'.$device->uuid.'/diagnostico';
        $this->get($url)->assertRedirect('/login');
        $user = User::query()->create(['name'=>'User','email'=>'user@example.test',
            'password'=>'password123456','is_admin'=>false]);
        $this->actingAs($user)->get($url)->assertForbidden();
        $admin = User::query()->create(['name'=>'Admin','email'=>'admin@example.test',
            'password'=>'password123456','is_admin'=>true]);
        // Inertia AJAX requests must send the asset version received during page load.
        // Otherwise, a compiled Vite manifest triggers the expected 409 full-reload response.
        $this->actingAs($admin)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) (app(HandleInertiaRequests::class)->version(request()) ?? ''),
        ])
            ->get($url)->assertOk()->assertJsonPath('component', 'Diagnostics/Show')
            ->assertJsonPath('props.diagnostic.hardware.data.Model', 'Notebook')
            ->assertJsonPath('props.events.data.0.provider', $event['provider'])
            ->assertDontSee('token_hash');
    }
}
