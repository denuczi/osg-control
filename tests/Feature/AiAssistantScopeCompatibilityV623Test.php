<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\ChatModel;
use App\Domain\AI\AssistantIntent;
use App\Domain\AI\AssistantScope;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceInventory;
use App\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantScopeCompatibilityV623Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'stub-model',
            'reports.ai.groq_key' => 'non-real-qa-value',
        ]);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('Scope or deterministic questions must not call AI provider');
            }
        });
        return User::query()->create([
            'name' => 'Administrador QA', 'email' => Str::uuid().'@example.test',
            'password' => 'testing-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, int $ramBytes, ?array $volumes = null): Device
    {
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'hostname' => $name,
            'platform' => 'Windows 11 Pro', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now()->subHour(), 'last_sampled_at' => now()->subHour(),
            'memory_total_bytes' => $ramBytes,
        ]);
        if ($volumes !== null) {
            DeviceInventory::query()->create([
                'device_id' => $device->id, 'captured_at' => now()->subHour(),
                'processor' => ['name' => 'Intel Core i5'],
                'volumes' => $volumes,
            ]);
        }
        return $device;
    }

    private function volume(string $mount, int $freeGb, ?string $media = 'SSD', string $bus = 'SATA'): array
    {
        return [
            'mountpoint' => $mount, 'device' => $mount,
            'free_bytes' => $freeGb * 1000000000,
            'removable' => false, 'media_type' => $media, 'bus_type' => $bus,
        ];
    }

    public function test_gta_v_question_uses_offline_inventories_without_inventing_full_compatibility(): void
    {
        $admin = $this->admin();
        $first = $this->device('LAB-RAM-4', 4 * 1024 ** 3, [
            $this->volume('C:\\', 140, 'HDD'),
        ]);
        $second = $this->device('NETBOOK-RAM-8', 8 * 1024 ** 3, [
            $this->volume('C:\\', 110, 'SSD'),
            $this->volume('X:\\', 900, 'SSD', 'USB'),
        ]);

        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Quiero descargar GTA V en alguna compu, ¿servirá alguna?',
        ])->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.kind', 'compatibility')
            ->assertJsonPath('evidence.0.data.registered', 2)
            ->assertJsonPath('evidence.0.data.compatibility_devices.0.uuid', $first->uuid)
            ->assertJsonPath('evidence.0.data.compatibility_devices.1.uuid', $second->uuid)
            ->assertJsonPath('evidence.0.data.compatibility_devices.0.online', false)
            ->assertJsonPath('evidence.0.data.compatibility_devices.1.online', false)
            ->assertJsonPath('evidence.0.data.compatibility_devices.0.editions.enhanced.ram', 'fail')
            ->assertJsonPath('evidence.0.data.compatibility_devices.0.editions.legacy.status', 'unverified')
            ->assertJsonPath('evidence.0.data.compatibility_devices.1.editions.enhanced.storage', 'pass')
            ->assertJsonPath('evidence.0.data.compatibility_devices.1.editions.enhanced.status', 'unverified');
        $this->assertStringContainsString('no está verificada', $response->json('answer'));
        $this->assertStringNotContainsString('no hay equipos conectados', mb_strtolower($response->json('answer')));
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $log = AuditLog::query()->where('event', 'ai.consulted')->firstOrFail();
        $this->assertSame('Compatibilidad de equipos con GTA V', $log->context['topic']);
        $this->assertArrayNotHasKey('question', $log->context);
    }

    public function test_unknown_hardware_does_not_turn_into_false_incompatibility_or_certified_success(): void
    {
        $admin = $this->admin();
        $this->device('SIN-INVENTARIO', 8 * 1024 ** 3);
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Puedo instalar GTA V en esta computadora?',
        ])->assertOk()->assertJsonPath('evidence.0.data.compatibility_devices.0.editions.enhanced.storage', 'unknown')
            ->assertJsonPath('evidence.0.data.compatibility_devices.0.editions.enhanced.gpu', 'unknown')
            ->assertJsonPath('evidence.0.data.compatibility_devices.0.editions.enhanced.status', 'unverified');
    }

    public function test_gta_v_discards_remote_and_usb_capacity_and_checks_single_volume(): void
    {
        $admin = $this->admin();
        $this->device('UNIDADES-FALSAS', 8 * 1024 ** 3, [
            $this->volume('C:\\', 70, 'SSD'),
            $this->volume('D:\\', 80, 'SSD'),
            $this->volume('E:\\', 600, 'SSD', 'USB'),
            $this->volume('Z:\\', 800, 'SSD', 'Network'),
        ]);
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Sirve GTA V en este equipo?',
        ])->assertOk()->assertJsonPath('evidence.0.data.compatibility_devices.0.editions.enhanced.storage', 'fail')
            ->assertJsonPath('evidence.0.data.compatibility_devices.0.editions.legacy.storage', 'fail');
    }

    public function test_out_of_scope_question_is_rejected_before_model_and_not_logged_verbatim(): void
    {
        $admin = $this->admin();
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Me enamoré de mi prima, ¿qué hago?',
        ])->assertOk()->assertJsonPath('evidence', [])->assertJsonPath('proposal', null);
        $this->assertStringContainsString('infraestructura', $result->json('answer'));
        $log = AuditLog::query()->firstOrFail();
        $this->assertSame('ai.out_of_scope', $log->event);
        $this->assertStringNotContainsString('prima', json_encode($log->context));
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_activity_has_human_label_and_consulted_topic_without_internal_event_key(): void
    {
        $admin = $this->admin();
        $this->device('LAB-A', 8 * 1024 ** 3);
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora tiene más RAM?',
        ])->assertOk();
        $headers = [
            'X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) (app(HandleInertiaRequests::class)->version(request()) ?? ''),
        ];
        $this->withHeaders($headers)->get('/actividad')
            ->assertOk()->assertJsonPath('props.logs.data.0.event_label', 'Consulta a OSG Nodo AI')
            ->assertJsonPath('props.logs.data.0.summary', 'Consulta: Comparación de memoria RAM instalada.');
    }

    public function test_intent_and_scope_recognize_infrastructure_not_personal_chat(): void
    {
        $this->assertSame('software_compatibility', app(AssistantIntent::class)
            ->readPlan('Quiero descargar GTA V en alguna compu, ¿servirá alguna?'));
        // Estas variantes deben permanecer en el flujo local, sin llamar al proveedor.
        foreach ([
            '¿Sirve GTA V en este equipo?',
            '¿Sirven mis computadoras para GTA V?',
            '¿Funciona GTA V en mi computadora?',
            '¿Funcionaría GTA V en alguna PC?',
            '¿Podría instalar GTA V en un equipo?',
        ] as $question) {
            $this->assertSame('software_compatibility', app(AssistantIntent::class)->readPlan($question), $question);
        }
        $this->assertTrue(app(AssistantScope::class)->allows('¿Qué juegos puedo instalar en mis equipos?'));
        $this->assertFalse(app(AssistantScope::class)->allows('Me enamoré de mi prima, ¿qué hago?'));
        $this->assertFalse(app(AssistantScope::class)->allows('¿Cómo preparo una lasaña?'));
    }

    public function test_empty_online_comparison_does_not_emit_blank_device_table(): void
    {
        $admin = $this->admin();
        $this->device('SIN-CONEXION', 8 * 1024 ** 3);
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora conectada tiene más RAM?',
        ])->assertOk()->assertJsonPath('evidence', [])->assertJsonPath('proposal', null);
        $this->assertStringContainsString('No hay equipos conectados', $result->json('answer'));
    }

    public function test_activity_can_filter_ai_consultations(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora tiene más RAM?',
        ])->assertOk();
        AuditLog::query()->create(['user_id' => $admin->id, 'event' => 'settings.updated', 'context' => []]);
        $headers = [
            'X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) (app(HandleInertiaRequests::class)->version(request()) ?? ''),
        ];
        $this->withHeaders($headers)->get('/actividad?category=assistant')
            ->assertOk()->assertJsonPath('props.logs.total', 1)
            ->assertJsonPath('props.logs.data.0.event', 'ai.consulted');
    }

}
