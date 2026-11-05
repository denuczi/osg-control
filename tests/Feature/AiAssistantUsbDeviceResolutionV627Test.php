<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantIntent;
use App\Domain\AI\Contracts\ChatModel;
use App\Domain\AI\Tools\DeviceIdentityResolver;
use App\Domain\AI\Tools\FleetTools;
use App\Models\Device;
use App\Models\DeviceMediaEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantUsbDeviceResolutionV627Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'stub',
            'reports.ai.groq_key' => 'test-key-only',
        ]);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('La consulta USB dirigida debe resolverse localmente');
            }
        });
        return User::query()->create([
            'name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'testing-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, string $hostname): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'hostname' => $hostname,
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now()->subHours(12),
        ]);
    }

    private function connection(Device $device, string $label): DeviceMediaEvent
    {
        return DeviceMediaEvent::query()->create([
            'device_id' => $device->id, 'event_uuid' => (string) Str::uuid(),
            'kind' => 'connected', 'observed_at' => now()->subHour(), 'received_at' => now(),
            'volume' => ['mountpoint' => 'D:\\', 'media_class' => 'usb', 'removable' => true, 'label' => $label],
        ]);
    }

    private function usbHistory(): array
    {
        return [
            ['role' => 'user', 'content' => '¿Cuáles fueron las últimas computadoras que conectaron un pendrive?'],
            ['role' => 'assistant', 'content' => 'Se encontraron tres conexiones de almacenamiento USB.'],
        ];
    }

    public function test_name_lookup_is_accent_insensitive_and_offline_does_not_mean_missing(): void
    {
        $user = $this->admin();
        $device = $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $this->device('DESKTOP-RAVF7FN', 'DESKTOP-RAVF7FN');
        $response = app(FleetTools::class)->execute('find_devices', ['search' => 'Felix'], $user->id);
        $this->assertSame(1, $response['result']['total_matching']);
        $this->assertSame($device->uuid, $response['result']['devices'][0]['uuid']);
        $this->assertFalse($response['result']['devices'][0]['online']);
        $this->assertSame('found', app(DeviceIdentityResolver::class)->matches('Netbook de Félix')['status']);
    }

    public function test_followup_targets_felix_and_distinguishes_zero_usb_events_from_missing_device(): void
    {
        $admin = $this->admin();
        $felix = $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $other = $this->device('DESKTOP-RAVF7FN', 'DESKTOP-RAVF7FN');
        $this->connection($other, 'PENDRIVE');
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Y la computadora de felix?', 'history' => $this->usbHistory(),
        ])->assertOk()
            ->assertJsonPath('evidence.0.kind', 'usb_connections')
            ->assertJsonPath('evidence.0.data.target_device.uuid', $felix->uuid)
            ->assertJsonPath('evidence.0.data.connection_events', 0)
            ->assertJsonPath('evidence.0.data.usb_events', []);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Y la computadora de felix?', 'history' => $this->usbHistory(),
        ])->assertOk()->json('answer');
        $this->assertStringContainsString('sí está registrada', $answer);
        $this->assertStringContainsString('no encontré conexiones', $answer);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_hostname_resolution_after_a_second_followup_filters_the_correct_device(): void
    {
        $admin = $this->admin();
        $felix = $this->device('Netbook de Félix', 'DESKTOP-CN1BDGF');
        $other = $this->device('DESKTOP-RAVF7FN', 'DESKTOP-RAVF7FN');
        $this->connection($felix, 'PERSONAL');
        $this->connection($other, 'PENDRIVE');
        $history = [...$this->usbHistory(),
            ['role' => 'user', 'content' => '¿Y la computadora de Félix?'],
            ['role' => 'assistant', 'content' => '¿Me indicás el hostname?'],
        ];
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'La computadora de Félix es el equipo DESKTOP-CN1BDGF',
            'history' => $history,
        ])->assertOk()
            ->assertJsonPath('evidence.0.data.connection_events', 1)
            ->assertJsonPath('evidence.0.data.usb_events.0.device_uuid', $felix->uuid)
            ->assertJsonPath('evidence.0.data.usb_events.0.label', 'PERSONAL');
    }

    public function test_direct_usb_question_filters_by_a_named_device_and_retains_time_window(): void
    {
        $admin = $this->admin();
        $felix = $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $this->connection($felix, 'CLASE')->update(['observed_at' => \Carbon\CarbonImmutable::now((string) config('nodo.network_report_timezone', 'America/Argentina/Cordoba'))->startOfDay()->utc()]);
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Se conectó un pendrive en la netbook de Felix hoy?',
        ])->assertOk()
            ->assertJsonPath('evidence.0.data.period', 'today')
            ->assertJsonPath('evidence.0.data.target_device.uuid', $felix->uuid)
            ->assertJsonPath('evidence.0.data.connection_events', 1);
    }

    public function test_ambiguous_names_are_not_assigned_to_an_arbitrary_computer(): void
    {
        $admin = $this->admin();
        $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $this->device('Oficina Félix', 'DESKTOP-AAAA111');
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Y la computadora de Felix?', 'history' => $this->usbHistory(),
        ])->assertOk();
        $this->assertStringContainsString('Encontré **2 equipos**', $result->json('answer'));
        $this->assertSame([], $result->json('evidence'));
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_device_question_does_not_inherit_unrelated_usb_context(): void
    {
        $intent = app(AssistantIntent::class);
        $this->assertSame('usb_device_connections', $intent->readPlan(
            '¿Y la computadora de Félix?', null, $this->usbHistory(),
        ));
        $this->assertNull($intent->readPlan('¿Y la computadora de Félix?', null, [
            ...$this->usbHistory(),
            ['role' => 'user', 'content' => '¿Cuál tiene más RAM?'],
        ]));
        $this->assertSame('usb_device_connections', $intent->readPlan('¿Y Félix?', null, $this->usbHistory()));
        $this->assertFalse($intent->permitsActions('¿Y la computadora de Félix?'));
    }
}
