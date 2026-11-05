<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantIntent;
use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantMessageActionsV628Test extends TestCase
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
                throw new \LogicException('Un mensaje explícito no debe necesitar el LLM');
            }
        });
        return User::query()->create([
            'name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'testing-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, bool $online): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name, 'hostname' => 'DESKTOP-'.strtoupper(Str::random(7)),
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => $online ? now() : now()->subHours(2),
            'is_demo' => false,
        ]);
    }

    public function test_singular_online_equipment_produces_only_a_confirmable_courteous_message(): void
    {
        $admin = $this->admin();
        $online = $this->device('Laboratorio 01', true);
        $this->device('Netbook sin conexión', false);

        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'al equipo prendido enviale un mensaje diciendo "El servidor estará inactivo por 1hs" (cordial)',
        ])->assertOk()
            ->assertJsonPath('proposal.action', 'show_message')
            ->assertJsonPath('proposal.recipients.0.uuid', $online->uuid)
            ->assertJsonPath('evidence', [])
            ->json();

        $this->assertStringContainsString('El servidor estará inactivo por 1hs', $result['proposal']['message']);
        $this->assertStringContainsString('Gracias por tu comprensión', $result['proposal']['message']);
        $this->assertStringContainsString('Todavía no se envió', $result['answer']);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ai.action_prepared']);

        $this->postJson('/asistente/propuestas/'.$result['proposal']['uuid'].'/confirmar', [
            'confirm' => true,
        ])->assertOk()->assertJsonPath('orders_registered', 1);
        $this->assertDatabaseCount('device_commands', 1);
    }

    public function test_singular_connected_reference_never_targets_multiple_computers(): void
    {
        $admin = $this->admin();
        $this->device('PC A', true);
        $this->device('PC B', true);

        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Al equipo encendido enviale un mensaje diciendo "Atención"',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('más de una computadora conectada', $answer);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_no_online_device_does_not_queue_a_message_for_later(): void
    {
        $admin = $this->admin();
        $this->device('Desconectada', false);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Al equipo prendido enviale un mensaje diciendo "Aviso"',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('No hay ningún equipo conectado', $answer);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_explicit_all_online_creates_a_single_approval_for_actual_recipients(): void
    {
        $admin = $this->admin();
        $this->device('PC A', true);
        $this->device('PC B', true);
        $this->device('PC C sin conexión', false);
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Enviales a todos los equipos conectados un mensaje diciendo "Habrá mantenimiento"',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')->json();
        $this->assertCount(2, $result['proposal']['recipients']);
        $this->assertDatabaseCount('ai_action_proposals', 1);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_named_host_is_not_confused_with_an_ordinal_or_other_device(): void
    {
        $admin = $this->admin();
        $device = $this->device('Félix', true);
        $this->device('Otra computadora', true);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Envía un mensaje a '.$device->hostname.' diciendo "Buen día"',
        ])->assertOk()->assertJsonPath('proposal.recipients.0.uuid', $device->uuid)->json();
        $this->assertCount(1, $answer['proposal']['recipients']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_missing_quoted_text_asks_for_message_instead_of_hallucinating_it(): void
    {
        $admin = $this->admin();
        $this->device('PC A', true);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                return ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'missing_content', 'type' => 'function',
                    'function' => ['name' => \App\Domain\AI\AssistantRequestInterpreter::TOOL_NAME,
                        'arguments' => json_encode(['intent' => 'message', 'target' => 'single_online',
                            'reference' => null, 'message' => null, 'tone' => 'cordial'])],
                ]]];
            }
        });
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Al equipo prendido enviale un mensaje cordial',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('Qué mensaje querés enviar', $answer);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_where_is_it_followup_points_to_real_ui_and_never_reuses_action(): void
    {
        $admin = $this->admin();
        $this->device('PC A', true);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿en qué parte?',
            'history' => [
                ['role' => 'user', 'content' => 'Al equipo prendido enviale un mensaje diciendo "Mantenimiento"'],
                ['role' => 'assistant', 'content' => 'No tengo acceso para enviar mensajes'],
            ],
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('**Equipos**', $answer);
        $this->assertStringContainsString('**Mostrar mensaje**', $answer);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }


    public function test_quoted_text_does_not_change_recipient_scope(): void
    {
        $admin = $this->admin();
        $intended = $this->device('LAB-PC-01', true);
        $this->device('LAB-PC-02', true);
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Enviale un mensaje a '.$intended->hostname.' diciendo "Avisá a todos los equipos conectados que hay reunión"',
        ])->assertOk()->json();
        $this->assertCount(1, $result['proposal']['recipients']);
        $this->assertSame($intended->uuid, $result['proposal']['recipients'][0]['uuid']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_excluded_subset_is_never_sent_to_every_online_device(): void
    {
        $admin = $this->admin();
        $this->device('LAB-PC-01', true);
        $this->device('LAB-PC-02', true);
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Envía a todos los equipos conectados menos el del laboratorio un mensaje diciendo "Aviso"',
        ])->assertOk()->assertJsonPath('proposal', null)->json();
        $this->assertStringContainsString('seleccioná los destinatarios concretos', $result['answer']);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_followup_without_previous_message_action_does_not_inherit_permission(): void
    {
        $intent = app(AssistantIntent::class);
        $this->assertFalse($intent->asksWhereToSendMessage('¿En qué parte?', [
            ['role' => 'user', 'content' => '¿Cuánto espacio libre tiene la computadora?'],
        ]));
    }

    public function test_readonly_and_quoted_action_words_never_trigger_message_proposals(): void
    {
        $intent = app(AssistantIntent::class);
        $this->assertFalse($intent->requestsMessageAction('¿Dónde puedo enviar un mensaje?'));
        $this->assertFalse($intent->requestsMessageAction('¿Cómo se envía un mensaje?'));
        $this->assertFalse($intent->requestsMessageAction('No envíes un mensaje al equipo'));
        $this->assertFalse($intent->requestsMessageAction('¿Cuántos equipos dicen "enviale un mensaje"?'));
        $this->assertTrue($intent->requestsMessageAction('al equipo prendido enviale un mensaje diciendo "Hola"'));
    }
}
