<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantIntent;
use App\Domain\AI\AssistantScope;
use App\Domain\AI\AssistantRequestInterpreter;
use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantBroadTechnologyV633Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['assistant.enabled' => true, 'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'modelo-qa', 'reports.ai.groq_key' => 'test-only']);
        return User::query()->create(['name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'test-only-password', 'is_admin' => true]);
    }

    private function device(string $name, float $cpu, float $ram, float $disk): Device
    {
        return Device::query()->create(['uuid' => (string) Str::uuid(), 'name' => $name,
            'hostname' => 'PC-'.strtoupper(Str::random(7)), 'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now(), 'last_sampled_at' => now(),
            'cpu_percent' => $cpu, 'memory_percent' => $ram, 'disk_percent' => $disk,
            'memory_total_bytes' => 8 * 1024 ** 3, 'is_demo' => false]);
    }

    public function test_device_context_allows_elliptical_upgrade_advice_without_model(): void
    {
        $admin = $this->admin();
        $device = $this->device('Oficina Norte', 91, 93, 92);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array {
                throw new \LogicException('Deterministic hardware advice must not invoke LLM');
            }
        });
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Crees que es momento de cambiar los componentes?',
            'device_uuid' => $device->uuid,
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertStringContainsString('RAM', $response->json('answer'));
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_performance_diagnosis_uses_metrics_not_top_processes(): void
    {
        $admin = $this->admin();
        $this->device('Puesto Norte', 93, 96, 96);
        $this->device('Puesto Sur', 15, 28, 30);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array {
                throw new \LogicException('Deterministic diagnosis must not invoke LLM');
            }
        });
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora tiene problema de rendimiento?',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertStringContainsString('Puesto Norte', $response->json('answer'));
        $this->assertStringNotContainsString('procesos con más memoria', $response->json('answer'));
    }

    public function test_colloquial_plural_message_only_prepares_pending_proposal(): void
    {
        $admin = $this->admin();
        $this->device('Laboratorio Uno', 11, 28, 30);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                if (count($tools) !== 1 || ($tools[0]['function']['name'] ?? null) !== AssistantRequestInterpreter::TOOL_NAME) {
                    throw new \LogicException('Expected semantic router only');
                }
                return ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'intent1', 'type' => 'function', 'function' => [
                        'name' => AssistantRequestInterpreter::TOOL_NAME,
                        'arguments' => json_encode(['intent' => 'message', 'target' => 'all_online',
                            'reference' => null, 'message' => 'El servidor se actualizará dentro de un momento.',
                            'tone' => 'cordial'], JSON_THROW_ON_ERROR),
                    ],
                ]]];
            }
        });
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Dile a las conectadas que dentro de un momento se actualizará el servidor',
        ])->assertOk();
        $this->assertSame('pending', $response->json('proposal.status'));
        $this->assertCount(1, $response->json('proposal.recipients'));
        $this->assertDatabaseCount('ai_action_proposals', 1);
    }

    public function test_ambiguous_non_explicit_plural_never_creates_proposal(): void
    {
        $intent = app(AssistantIntent::class);
        $this->assertFalse($intent->requestsMessageAction('¿Cómo envío mensajes a las conectadas?'));
        $this->assertFalse($intent->requestsMessageAction('No les avises a las conectadas que se actualizará'));
        $this->assertTrue($intent->requestsMessageAction('Dile a las conectadas que se actualizará el servidor'));
        $this->assertTrue(app(AssistantScope::class)->allows('¿Cómo funciona Spanning Tree en un switch?'));
    }
}
