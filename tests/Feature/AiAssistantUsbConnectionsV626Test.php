<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantIntent;
use App\Domain\AI\AssistantScope;
use App\Domain\AI\Contracts\ChatModel;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceMediaEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantUsbConnectionsV626Test extends TestCase
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
                throw new \LogicException('A USB history question must be answered using local records, not an AI provider');
            }
        });
        return User::query()->create([
            'name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'testing-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, bool $demo = false): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'hostname' => $name,
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now()->subHours(8), 'is_demo' => $demo,
        ]);
    }

    private function event(Device $device, string $kind, string $mediaClass = 'usb', int $daysAgo = 0, string $label = 'Pendrive'): DeviceMediaEvent
    {
        return DeviceMediaEvent::query()->create([
            'device_id' => $device->id,
            'event_uuid' => (string) Str::uuid(),
            'kind' => $kind,
            'observed_at' => now()->subDays($daysAgo)->subMinutes(2),
            'received_at' => now(),
            'volume' => [
                'device' => 'E:', 'mountpoint' => 'E:\\', 'media_class' => $mediaClass,
                'removable' => true, 'label' => $label,
                'serial' => 'PRIVATE-SERIAL-DO-NOT-EXPOSE',
                'total_bytes' => 8000000, 'used_bytes' => 0, 'free_bytes' => 8000000,
            ],
        ]);
    }

    public function test_recent_connection_question_uses_offline_usb_events_without_a_model_or_commands(): void
    {
        $admin = $this->admin();
        $first = $this->device('LAB-PC-01');
        $second = $this->device('NETBOOK-02');
        $this->event($first, 'connected', 'usb', 0, 'TRABAJO');
        $this->event($second, 'connected', 'usb', 1, 'DATOS');
        $this->event($first, 'disconnected');
        $this->event($first, 'detected');
        $this->event($first, 'connected', 'optical');

        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Alguno conectó un pendrive recientemente?',
        ])->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.kind', 'usb_connections')
            ->assertJsonPath('evidence.0.data.connection_events', 2)
            ->assertJsonPath('evidence.0.data.devices_with_connections', 2)
            ->assertJsonCount(2, 'evidence.0.data.usb_events');

        $this->assertStringContainsString('2 eventos de conexión', $response->json('answer'));
        $this->assertStringContainsString('no identifican a la persona', $response->json('answer'));
        $this->assertSame($first->uuid, $response->json('evidence.0.data.usb_events.0.device_uuid'));
        $this->assertSame('TRABAJO', $response->json('evidence.0.data.usb_events.0.label'));
        $this->assertStringNotContainsString('PRIVATE-SERIAL', json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertSame('Conexiones recientes de almacenamiento USB',
            AuditLog::query()->where('event', 'ai.consulted')->firstOrFail()->context['topic']);
    }

    public function test_excludes_optical_initial_detection_old_devices_and_archived_revoked_demo(): void
    {
        $admin = $this->admin();
        $allowed = $this->device('VALIDO');
        $archived = $this->device('ARCHIVADO');
        $archived->update(['archived_at' => now()]);
        $revoked = $this->device('REVOCADO');
        $revoked->update(['revoked_at' => now()]);
        $demo = $this->device('DEMOSTRACION', true);
        foreach ([$archived, $revoked, $demo] as $d) $this->event($d, 'connected');
        $this->event($allowed, 'detected');
        $this->event($allowed, 'disconnected');
        $this->event($allowed, 'connected', 'optical');
        $this->event($allowed, 'connected', 'usb', 11);

        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Conectaron alguna memoria USB recientemente?',
        ])->assertOk()->assertJsonPath('evidence.0.kind', 'usb_connections')
            ->assertJsonPath('evidence.0.data.connection_events', 0)
            ->assertJsonPath('evidence.0.data.usb_events', []);
        $this->assertStringContainsString('No encontré', $response->json('answer'));
        $this->assertStringContainsString('no demuestra', mb_strtolower($response->json('answer')));
    }

    public function test_period_says_yesterday_without_including_today_or_old_connections(): void
    {
        $admin = $this->admin();
        $device = $this->device('EQUIPO');
        $today = CarbonImmutable::now((string) config('nodo.network_report_timezone', 'America/Argentina/Cordoba'))->startOfDay();
        $this->event($device, 'connected', 'usb', 0, 'HOY')
            ->update(['observed_at' => $today->utc()]);
        $this->event($device, 'connected', 'usb', 1, 'AYER')
            ->update(['observed_at' => $today->subDay()->addHours(12)->utc()]);
        $this->event($device, 'connected', 'usb', 4, 'ANTERIOR')
            ->update(['observed_at' => $today->subDays(4)->addHours(12)->utc()]);

        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Se conectó un pendrive ayer?',
        ])->assertOk()->assertJsonPath('evidence.0.data.period', 'yesterday')
            ->assertJsonPath('evidence.0.data.connection_events', 1)
            ->assertJsonPath('evidence.0.data.usb_events.0.label', 'AYER');
        $this->assertStringContainsString('ayer', $response->json('answer'));
    }

    public function test_many_connections_are_bounded_and_report_the_full_count(): void
    {
        $admin = $this->admin();
        $device = $this->device('LAB-USB');
        for ($i = 0; $i < 36; $i++) {
            $this->event($device, 'connected', 'usb', 0, 'MEDIA-'.$i);
        }
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué pendrives conectaron recientemente?',
        ])->assertOk()->assertJsonPath('evidence.0.data.connection_events', 36)
            ->assertJsonPath('evidence.0.data.devices_with_connections', 1)
            ->assertJsonPath('evidence.0.data.truncated', true)
            ->assertJsonCount(30, 'evidence.0.data.usb_events');
        $this->assertStringContainsString('36 eventos', $response->json('answer'));
        $this->assertStringContainsString('no todas las registradas', $response->json('answer'));
    }

    public function test_scope_recognizes_usb_synonyms_but_not_unrelated_personal_chats(): void
    {
        $intent = app(AssistantIntent::class);
        $scope = app(AssistantScope::class);
        foreach ([
            '¿Alguno conectó un pendrive recientemente?',
            '¿En qué computadora enchufaron una memoria USB?',
            '¿Cuáles unidades USB se conectaron hoy?',
            '¿Hay registros de conexiones de pendrives?',
        ] as $question) {
            $this->assertTrue($scope->allows($question), $question);
            $this->assertSame('usb_connections', $intent->readPlan($question), $question);
            $this->assertFalse($intent->permitsActions($question), $question);
        }
        $this->assertFalse($scope->allows('Me enamoré de mi prima, ¿qué hago?'));
    }
}
