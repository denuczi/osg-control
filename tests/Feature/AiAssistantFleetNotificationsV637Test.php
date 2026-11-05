<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantFleetNotificationsV637Test extends TestCase
{
    use RefreshDatabase;

    private const CONVERSATION = '6706e339-49b1-498e-a5f0-e8c46dfe5dc9';

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'test-model',
            'reports.ai.groq_key' => 'test-only',
        ]);
        // The direct path must work even when the external model is unavailable.
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('A clear fleet notice must not depend on a model call.');
            }
        });

        $admin = User::query()->create([
            'name' => 'Admin', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password', 'is_admin' => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function device(string $name, bool $online = true, bool $demo = false): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name, 'hostname' => 'WORKSTATION-'.strtoupper(Str::random(8)),
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => $online ? now() : now()->subHours(2),
            'is_demo' => $demo,
        ]);
    }

    public function test_unquoted_plural_active_computers_produces_courteous_approval_proposal(): void
    {
        $this->admin();
        $first = $this->device('Recepción');
        $second = $this->device('Laboratorio');
        $this->device('Desconectada', false);
        $this->device('Demo', true, true);

        $result = $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'envía un mensaje a las computadoras activas diciendo que se apagarán',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')->json();

        $this->assertEqualsCanonicalizing(
            [$first->uuid, $second->uuid],
            array_column($result['proposal']['recipients'], 'uuid'),
        );
        $text = $result['proposal']['message'];
        $this->assertStringContainsString('los equipos se apagarán', $text);
        $this->assertStringContainsString('Por favor, guarden sus trabajos', $text);
        $this->assertStringContainsString('Gracias por su comprensión', $text);
        $this->assertStringNotContainsString('en 1 hora', $text);
        $this->assertDatabaseCount('ai_action_proposals', 1);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_dile_a_las_conectadas_works_without_the_word_mensaje(): void
    {
        $this->admin();
        $this->device('PC A');
        $this->device('PC B');

        $result = $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'Dile a las conectadas que dentro de un momento se actualizará el servidor',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')->json();

        $this->assertCount(2, $result['proposal']['recipients']);
        $this->assertStringContainsString('dentro de un momento se actualizará el servidor', $result['proposal']['message']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_explicit_plural_recipients_work_without_conversation_id(): void
    {
        $this->admin();
        $this->device('PC A');

        $this->postJson('/asistente/consultar', [
            'question' => 'Comunica a los equipos activos diciendo que habrá mantenimiento',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message');
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_missing_message_asks_for_content_instead_of_inventing_it(): void
    {
        $this->admin();
        $this->device('PC A');
        $response = $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'Envía un mensaje a las computadoras activas',
        ])->assertOk()->assertJsonPath('proposal', null)->json();

        $this->assertStringContainsString('Qué texto', $response['answer']);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_no_online_recipients_cannot_create_proposal(): void
    {
        $this->admin();
        $this->device('Desconectada', false);
        $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'Envía un mensaje a las computadoras activas diciendo que se apagarán',
        ])->assertOk()->assertJsonPath('proposal', null);

        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_more_than_twenty_online_devices_never_silently_selects_a_subset(): void
    {
        $this->admin();
        for ($index = 1; $index <= 21; $index++) {
            $this->device('PC '.$index);
        }
        $response = $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'Envía un mensaje a las computadoras activas diciendo que se apagarán',
        ])->assertOk()->assertJsonPath('proposal', null)->json();

        $this->assertStringContainsString('límite de seguridad', $response['answer']);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_excluded_recipient_does_not_expand_to_the_entire_online_fleet(): void
    {
        $this->admin();
        $this->device('Laboratorio');
        $this->device('Recepción');
        $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'Envía un mensaje a los equipos activos excepto el laboratorio diciendo que habrá mantenimiento',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_new_fleet_notice_does_not_reuse_a_previous_pending_named_draft(): void
    {
        $this->admin();
        $this->device('Doctor');
        $this->device('Recepción');
        $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'di a la computadora que el servidor entrará en mantenimiento en 1hs',
        ])->assertOk()->assertJsonPath('proposal', null);

        $result = $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'envía un mensaje a las computadoras activas diciendo que se apagarán',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')->json();
        $this->assertCount(2, $result['proposal']['recipients']);
        $this->assertStringNotContainsString('mantenimiento', $result['proposal']['message']);
        $this->assertDatabaseCount('device_commands', 0);

        // The old draft cannot later be confirmed merely by a continuation.
        $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'envia el mensaje que pedí',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertDatabaseCount('ai_action_proposals', 1);
    }

    public function test_text_mentioning_another_group_does_not_change_named_recipient(): void
    {
        $this->admin();
        $named = $this->device('Recepción');
        $this->device('Laboratorio');
        $response = $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'Envía un mensaje a '.$named->hostname.' diciendo "Avisa a todas las computadoras activas"',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')->json();

        $this->assertCount(1, $response['proposal']['recipients']);
        $this->assertSame($named->uuid, $response['proposal']['recipients'][0]['uuid']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_negative_request_does_not_produce_a_message_proposal(): void
    {
        $this->admin();
        $this->device('PC A');
        $this->postJson('/asistente/consultar', [
            'conversation_id' => self::CONVERSATION,
            'question' => 'No envíes ningún mensaje a las computadoras activas',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }
}
