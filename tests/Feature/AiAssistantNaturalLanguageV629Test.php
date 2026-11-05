<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantRequestInterpreter;
use App\Domain\AI\Contracts\ChatModel;
use App\Domain\AI\Infrastructure\ChatCompletionModel;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantNaturalLanguageV629Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'modelo-qa',
            'reports.ai.groq_key' => 'solo-pruebas',
        ]);
        return User::query()->create([
            'name' => 'Operador', 'email' => Str::uuid().'@example.test',
            'password' => 'test-only-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, bool $online = true, array $processes = [], int $ageMinutes = 1): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name,
            'hostname' => 'DESKTOP-'.strtoupper(Str::random(8)),
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => $online ? now() : now()->subHours(2),
            'processes_sampled_at' => now()->subMinutes($ageMinutes),
            'processes' => $processes, 'is_demo' => false,
        ]);
    }

    /** @param array<string,mixed> $args */
    private function routed(array $args): void
    {
        $this->app->instance(ChatModel::class, new class($args) implements ChatModel {
            public int $calls = 0;

            public function __construct(private readonly array $args) {}

            public function complete(array $messages, array $tools): array
            {
                $this->calls++;
                if ($this->calls !== 1 || count($tools) !== 1 ||
                    ($tools[0]['function']['name'] ?? null) !== AssistantRequestInterpreter::TOOL_NAME) {
                    throw new \LogicException('Unexpected call after intent was resolved');
                }
                return [
                    'role' => 'assistant', 'content' => null,
                    'tool_calls' => [[
                        'id' => 'route_call', 'type' => 'function',
                        'function' => ['name' => AssistantRequestInterpreter::TOOL_NAME,
                            'arguments' => json_encode($this->args, JSON_THROW_ON_ERROR)],
                    ]],
                ];
            }
        });
    }

    private function intent(string $intent, string $target, ?string $reference = null, ?string $message = null, string $tone = 'neutral'): array
    {
        return compact('intent', 'target', 'reference', 'message', 'tone');
    }

    public function test_identity_is_first_person_and_uses_actual_config_without_model_call(): void
    {
        $admin = $this->admin();
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('Model identity must not be hallucinated by model');
            }
        });
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué modelo de inteligencia artificial sos?',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('Soy **OSG Nodo AI**', $answer);
        $this->assertStringContainsString('**modelo-qa**', $answer);
        $this->assertStringContainsString('**Groq**', $answer);
    }

    public function test_browser_question_checks_the_only_connected_equipment_snapshot(): void
    {
        $admin = $this->admin();
        $device = $this->device('Netbook Félix', true, [
            ['name' => 'C:\\Program Files\\BraveSoftware\\Brave\\brave.exe', 'pid' => 734, 'memory_mb' => 330],
            ['name' => 'msedgewebview2.exe', 'pid' => 900, 'memory_mb' => 200],
        ]);
        $this->device('Equipo apagado', false, [['name' => 'chrome.exe', 'pid' => 200, 'memory_mb' => 200]]);
        $this->routed($this->intent('browser', 'single_online'));
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿El equipo prendido tiene abierto algún navegador?',
        ])->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.kind', 'processes')
            ->assertJsonPath('evidence.0.data.device_uuid', $device->uuid)
            ->assertJsonPath('evidence.0.data.top_processes.0.pid', 734);
        $answer = $response->json('answer');
        $this->assertStringContainsString('**Brave**', $answer);
        $this->assertStringNotContainsString('**Microsoft Edge**', $answer);
        $this->assertStringContainsString('no que exista ahora una ventana abierta', $answer);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_absence_in_partial_capture_never_certifies_browser_is_closed(): void
    {
        $admin = $this->admin();
        $this->device('Equipo', true, [['name' => 'MsMpEng.exe', 'pid' => 34, 'memory_mb' => 120]], 40);
        $this->routed($this->intent('browser', 'single_online'));
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Se está usando Chrome en la PC prendida?',
        ])->assertOk()->assertJsonPath('evidence', [])->json('answer');
        $this->assertStringContainsString('No puedo asegurar que estén cerrados', $answer);
        $this->assertStringContainsString('no es reciente', $answer);
    }

    public function test_unquoted_cordial_message_uses_llm_intent_but_never_sends_before_approval(): void
    {
        $admin = $this->admin();
        $on = $this->device('PC conectada', true);
        $this->device('PC sin conexión', false);
        $this->routed($this->intent('message', 'single_online', null,
            'En 1 hora el servidor entrará en mantenimiento.', 'cordial'));
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'quiero que comuniques por mensaje a la computadora prendida diciendo cordialmente que en 1hs el servidor entrará en mantenimiento',
        ])->assertOk()->assertJsonPath('proposal.action', 'show_message')
            ->assertJsonPath('proposal.recipients.0.uuid', $on->uuid)
            ->assertJsonPath('evidence', []);
        $this->assertStringContainsString('Gracias por tu comprensión', $response->json('proposal.message'));
        $this->assertStringContainsString('Todavía no se envió', $response->json('answer'));
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 1);
    }

    public function test_ambiguous_singular_target_creates_no_proposal(): void
    {
        $admin = $this->admin();
        $this->device('PC A', true);
        $this->device('PC B', true);
        $this->routed($this->intent('message', 'single_online', null, 'Habrá mantenimiento'));
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Podrías comunicarle a la computadora prendida que habrá mantenimiento?',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('varias computadoras conectadas', $answer);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_model_cannot_expand_a_singular_request_to_all_online(): void
    {
        $admin = $this->admin();
        $this->device('PC A');
        $this->device('PC B');
        $this->routed($this->intent('message', 'all_online', null, 'Un mensaje'));
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Necesito comunicar al equipo conectado que habrá mantenimiento.',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertStringContainsString('alcance no identifica inequívocamente', $response->json('answer'));
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_model_cannot_invent_a_device_name_not_present_in_request(): void
    {
        $admin = $this->admin();
        $this->device('LAB-PC-01', true);
        $this->routed($this->intent('message', 'named', 'LAB-PC-01', 'Hola'));
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Comunicá amablemente al equipo prendido que habrá mantenimiento.',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_misclassified_action_intent_does_not_lead_to_false_capability_denial(): void
    {
        $admin = $this->admin();
        $this->device('PC A', true);
        $this->routed($this->intent('other', 'unknown'));
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Necesito que comuniques al equipo conectado que habrá mantenimiento.',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('Puedo preparar mensajes', $answer);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_message_scope_exclusions_never_expand_into_all_online(): void
    {
        $admin = $this->admin();
        $this->device('Oficina A', true);
        $this->device('Oficina B', true);
        $this->routed($this->intent('message', 'all_online', null, 'Mantenimiento'));
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Comunicá a todos los equipos conectados excepto los de la oficina un aviso de mantenimiento.',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('alcance no identifica inequívocamente', $answer);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_message_content_cannot_be_mistaken_for_all_recipients(): void
    {
        $admin = $this->admin();
        $this->device('PC A', true);
        $this->device('PC B', true);
        $this->routed($this->intent('message', 'all_online', null, 'A todos: el servidor se reinicia.'));
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Para la computadora prendida redactá un mensaje diciendo "A todos: el servidor se reinicia".',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('no identifica inequívocamente', $answer);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_named_device_cannot_be_inferred_from_quoted_message_content(): void
    {
        $admin = $this->admin();
        $this->device('Netbook Félix', true);
        $this->routed($this->intent('message', 'named', 'Félix', 'Félix está en mantenimiento.'));
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Necesito comunicar a la computadora prendida que el mensaje diga "Félix está en mantenimiento".',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_hypothetical_or_negated_request_cannot_prepare_an_action(): void
    {
        $admin = $this->admin();
        $this->device('PC de pruebas', true);
        foreach ([
            'No informes al equipo prendido que habrá mantenimiento.',
            '¿Cómo podría comunicarle al equipo conectado un aviso?',
        ] as $question) {
            $this->routed($this->intent('message', 'single_online', null, 'Mantenimiento'));
            $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
                'question' => $question,
            ])->assertOk()->assertJsonPath('proposal', null);
            $this->assertStringContainsString('No preparé ningún mensaje', $response->json('answer'));
            $this->assertDatabaseCount('ai_action_proposals', 0);
            $this->assertDatabaseCount('device_commands', 0);
        }
    }

    public function test_adapter_forces_only_semantic_router_tool(): void
    {
        $this->admin();
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'mock_call', 'type' => 'function',
                'function' => ['name' => AssistantRequestInterpreter::TOOL_NAME, 'arguments' => '{}'],
            ]]], 'finish_reason' => 'tool_calls']],
        ], 200)]);
        $definition = [
            'type' => 'function',
            'function' => ['name' => AssistantRequestInterpreter::TOOL_NAME,
                'description' => 'test', 'parameters' => ['type' => 'object', 'properties' => new \stdClass()]],
        ];
        app(ChatCompletionModel::class)->complete([['role' => 'user', 'content' => 'test']], [$definition]);
        Http::assertSent(static fn ($request): bool =>
            $request['tool_choice']['function']['name'] === AssistantRequestInterpreter::TOOL_NAME &&
            $request['parallel_tool_calls'] === false);
    }
}
