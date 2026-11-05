<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantIntent;
use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\MetricSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantMemoryAdviceV638Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'test-model',
            'reports.ai.groq_key' => 'fake-key-for-qa',
        ]);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('RAM explanation must not depend on a model call');
            }
        });
        return User::query()->create([
            'name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password', 'is_admin' => true,
        ]);
    }

    private function device(array $overrides = []): Device
    {
        return Device::query()->create(array_merge([
            'uuid' => (string) Str::uuid(), 'name' => 'Equipo Windows',
            'hostname' => 'PC-TEST', 'platform' => 'Windows 11 Pro',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now(), 'last_sampled_at' => now(),
            'memory_percent' => 50.2, 'memory_total_bytes' => 8 * 1024 ** 3,
            'memory_used_bytes' => 4 * 1024 ** 3, 'is_demo' => false,
        ], $overrides));
    }

    public function test_memory_question_without_selected_device_explains_without_inventing_fleet_data(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Por qué la memoria RAM está siempre al 50% a pesar de que no se está haciendo nada?',
        ])->assertOk()->assertJsonPath('proposal', null)->assertJsonPath('evidence', [])->json();
        $this->assertStringContainsString('caché', $response['answer']);
        $this->assertStringContainsString('ficha del equipo', $response['answer']);
        $this->assertStringNotContainsString('Presento los datos', $response['answer']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_selected_device_uses_bounded_ram_history_without_claiming_permanent_usage(): void
    {
        $admin = $this->admin();
        $device = $this->device();
        foreach ([49.5, 50.2, 50.7, 49.8] as $offset => $percentage) {
            MetricSample::query()->create([
                'device_id' => $device->id, 'cpu_percent' => 8,
                'memory_percent' => $percentage, 'disk_percent' => 20,
                'recorded_at' => now()->subSeconds(5 * ($offset + 1)),
            ]);
        }
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Por qué la memoria RAM está siempre al 50% a pesar de que no se está haciendo nada?',
            'device_uuid' => $device->uuid,
        ])->assertOk()->assertJsonPath('proposal', null)->json();
        $this->assertStringContainsString('Equipo Windows', $result['answer']);
        $this->assertStringContainsString('50,2 %', $result['answer']);
        $this->assertStringContainsString('49,5 % y 50,7 %', $result['answer']);
        $this->assertStringContainsString('no demuestra que haya sido así siempre', $result['answer']);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_recent_processes_are_context_not_an_attribution_of_all_ram(): void
    {
        $admin = $this->admin();
        $device = $this->device([
            'processes_sampled_at' => now(),
            'processes' => [
                ['pid' => 122, 'name' => 'chrome.exe', 'memory_mb' => 320.5],
                ['pid' => 345, 'name' => 'MsMpEng.exe', 'memory_mb' => 125],
            ],
        ]);
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Por qué se usa tanta memoria RAM en reposo?',
            'device_uuid' => $device->uuid,
        ])->assertOk()->json();
        $this->assertStringContainsString('chrome.exe', $result['answer']);
        $this->assertStringContainsString('captura puede ser parcial', $result['answer']);
        $this->assertStringContainsString('no explica por sí sola', $result['answer']);
    }

    public function test_stale_memory_is_not_reported_as_live(): void
    {
        $admin = $this->admin();
        $device = $this->device([
            'last_seen_at' => now()->subHours(3),
            'last_sampled_at' => now()->subHours(3),
        ]);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Por qué se mantiene la memoria RAM al 50%?',
            'device_uuid' => $device->uuid,
        ])->assertOk()->json('answer');
        $this->assertStringContainsString('desactualizada', $answer);
        $this->assertStringContainsString('no representa necesariamente el estado actual', $answer);
    }

    public function test_revoked_device_is_not_inspected(): void
    {
        $admin = $this->admin();
        $device = $this->device(['revoked_at' => now()]);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Por qué se mantiene la memoria RAM al 50%?',
            'device_uuid' => $device->uuid,
        ])->assertOk()->json('answer');
        $this->assertStringContainsString('ya no está disponible', $answer);
        $this->assertStringNotContainsString('Equipo Windows', $answer);
    }

    public function test_intent_is_narrow_and_does_not_intercept_unrelated_technology_or_actions(): void
    {
        $intent = app(AssistantIntent::class);
        $this->assertSame('memory_usage_advice', $intent->readPlan('¿Por qué la memoria RAM está siempre al 50%?'));
        $this->assertSame('memory_usage_advice', $intent->readPlan('¿Por qué se usa tanta RAM en reposo?'));
        $this->assertNotSame('memory_usage_advice', $intent->readPlan('¿Qué es la memoria RAM?'));
        $this->assertNotSame('memory_usage_advice', $intent->readPlan('Envía un mensaje a las computadoras activas diciendo que la RAM está al 50%'));
    }
}
