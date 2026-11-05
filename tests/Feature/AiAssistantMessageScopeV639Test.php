<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantMessagePlanner;
use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantMessageScopeV639Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'test-model',
            'reports.ai.groq_key' => 'test-only',
        ]);

        return User::query()->create([
            'name' => 'Admin', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, bool $online = true): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name,
            'hostname' => 'WORKSTATION-'.strtoupper(Str::random(7)),
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => $online ? now() : now()->subHours(2),
            'is_demo' => false,
        ]);
    }

    private function routed(array $interpretation): void
    {
        $this->app->instance(ChatModel::class, new class($interpretation) implements ChatModel {
            public int $calls = 0;

            public function __construct(private readonly array $result) {}

            public function complete(array $messages, array $tools): array
            {
                $this->calls++;

                return ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'scope_v639', 'type' => 'function',
                    'function' => [
                        'name' => \App\Domain\AI\AssistantRequestInterpreter::TOOL_NAME,
                        'arguments' => json_encode($this->result),
                    ],
                ]]];
            }
        });
    }

    public function test_explicit_fleet_exclusion_without_message_text_is_rejected_before_content_check(): void
    {
        $admin = $this->admin();
        $this->device('Oficina');
        $this->device('Laboratorio');
        $this->routed([
            'intent' => 'message', 'target' => 'all_online',
            'reference' => null, 'message' => 'Mantenimiento', 'tone' => 'neutral',
        ]);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Comunicá a todos los equipos conectados excepto los de la oficina un aviso de mantenimiento.',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('alcance no identifica inequívocamente', $answer);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_indirect_request_cannot_target_name_found_only_inside_message(): void
    {
        $admin = $this->admin();
        $this->device('Netbook Félix');
        $this->routed([
            'intent' => 'message', 'target' => 'named',
            'reference' => 'Félix', 'message' => 'Félix está en mantenimiento.', 'tone' => 'neutral',
        ]);
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Necesito comunicar a la computadora prendida que el mensaje diga "Félix está en mantenimiento".',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertSame(1, $this->app->make(ChatModel::class)->calls);
    }

    public function test_exclusion_in_quoted_message_does_not_restrict_explicit_fleet(): void
    {
        $admin = $this->admin();
        $this->device('Oficina');
        $this->device('Laboratorio');
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('Una orden explícita no requiere el modelo.');
            }
        });
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Envía a todos los equipos conectados un mensaje diciendo "Excepto los de la oficina, habrá mantenimiento"',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')->json();
        $this->assertCount(2, $response['proposal']['recipients']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_direct_fleet_exclusion_never_expands_with_text_present(): void
    {
        $admin = $this->admin();
        $this->device('Oficina');
        $this->device('Laboratorio');
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Envía a todos los equipos conectados menos el laboratorio un mensaje diciendo "Aviso"',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_direct_imperative_still_prepares_one_connected_device(): void
    {
        $admin = $this->admin();
        $target = $this->device('Laboratorio');
        $this->device('Desconectada', false);
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Al equipo prendido envíale un mensaje diciendo "Habrá mantenimiento"',
        ])->assertOk()->assertJsonPath('proposal.recipients.0.uuid', $target->uuid);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_direct_quoted_message_detection_distinguishes_imperative_and_indirect(): void
    {
        $planner = app(AssistantMessagePlanner::class);
        $this->assertTrue($planner->supportsDirectQuotedMessage('Al equipo prendido envíale un mensaje diciendo "Aviso"'));
        $this->assertFalse($planner->supportsDirectQuotedMessage('Necesito comunicar a la computadora prendida que el mensaje diga "Félix está en mantenimiento"'));
    }
}
