<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantIntent;
use App\Domain\AI\AssistantScope;
use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for the 503 returned when a message to a concrete hostname
 * unnecessarily fell through to the generative model in v6.3.3.
 */
final class AiAssistantRegressionV635Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'qa-only',
            'reports.ai.groq_key' => 'qa-key-not-real',
        ]);

        // These requests must never depend on a provider or spend tokens.
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('This request must be served without calling the model');
            }
        });

        return User::query()->create([
            'name' => 'Administración',
            'email' => Str::uuid().'@example.test',
            'password' => 'testing-password',
            'is_admin' => true,
        ]);
    }

    private function device(string $name, string $hostname): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'hostname' => $hostname,
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now(),
            'is_demo' => false,
        ]);
    }

    public function test_a_hostname_without_a_known_prefix_is_resolved_without_model_call(): void
    {
        $admin = $this->admin();
        $target = $this->device('Equipo de recepción', 'WORKSTATION-42');
        $this->device('Equipo de laboratorio', 'LAB-ANOTHER-02');

        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Envía un mensaje a WORKSTATION-42 diciendo "Buen día"',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')
            ->assertJsonPath('proposal.recipients.0.uuid', $target->uuid)->json();

        $this->assertCount(1, $result['proposal']['recipients']);
        $this->assertDatabaseCount('ai_action_proposals', 1);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_another_explicit_hostname_is_not_overridden_by_quoted_mass_recipient_text(): void
    {
        $admin = $this->admin();
        $target = $this->device('Oficina', 'OFFICE_NODE-15');
        $this->device('Laboratorio', 'LAB-ANOTHER-02');

        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Enviale un mensaje a OFFICE_NODE-15 diciendo "Avisa a todos los equipos conectados"',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')->json();

        $this->assertCount(1, $result['proposal']['recipients']);
        $this->assertSame($target->uuid, $result['proposal']['recipients'][0]['uuid']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_ambiguous_followup_without_context_never_calls_model(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué recomiendas hacer para reducir esto?',
        ])->assertOk()->assertJsonPath('proposal', null)->assertJsonPath('evidence', []);

        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_technology_is_allowed_but_clear_cooking_questions_are_not(): void
    {
        $scope = app(AssistantScope::class);
        $this->assertTrue($scope->allows('¿Cómo funciona Spanning Tree en redes?'));
        $this->assertTrue($scope->allows('¿Cómo funciona una red neuronal?'));
        $this->assertFalse($scope->allows('¿Cómo preparo una lasaña?'));
        $this->assertFalse($scope->allows('¿Qué recomiendas hacer para reducir esto?'));
        $this->assertFalse($scope->allows('¿Qué recomiendas hacer para reducir esto?', [
            ['role' => 'user', 'content' => '¿Cómo preparo una receta?'],
        ]));
        $this->assertTrue($scope->allows('¿Qué recomiendas hacer para reducir esto?', [
            ['role' => 'user', 'content' => '¿Qué equipo tiene la CPU saturada?'],
        ]));
        $this->assertFalse(app(AssistantIntent::class)->requestsMessageAction('¿Cómo envío un mensaje a WORKSTATION-42?'));
    }
}
