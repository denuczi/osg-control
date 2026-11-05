<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantReportPlanner;
use App\Domain\AI\Contracts\ChatModel;
use App\Jobs\GenerateDeviceReport;
use App\Jobs\GenerateFleetReport;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantGroundingReportsV631Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'qa-model',
            'reports.ai.groq_key' => 'qa-not-a-real-key',
        ]);
        return User::query()->create([
            'name' => 'QA admin', 'email' => Str::uuid().'@example.test',
            'password' => 'qa-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, string $hostname, int $ramGiB = 8): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'hostname' => $hostname,
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now()->subHour(), 'last_sampled_at' => now()->subHour(),
            'memory_total_bytes' => $ramGiB * (1024 ** 3),
        ]);
    }

    private function fakeInventedText(): void
    {
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                return ['content' => 'Hay 5 equipos: servidor-alpha, workstation-01, laptop-pro y servidor-beta. UUID: 6f3e9a12-4bcd-4a8f-9d3e-2b7c1e0f5a1d.', 'tool_calls' => []];
            }
        });
    }

    private function forbidModel(): void
    {
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('La consulta debe resolverse con datos de Laravel, no el LLM.');
            }
        });
    }

    public function test_inventory_request_queues_real_report_instead_of_inventing_devices(): void
    {
        Bus::fake();
        $this->forbidModel();
        $admin = $this->admin();
        $this->device('Computador del Doctor', 'DESKTOP-RAVF7FN', 4);
        $this->device('Netbook Félix', 'DESKTOP-CN1BDGF', 8);

        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Quiero un inventario de los dispositivos',
        ])->assertOk()->assertJsonPath('report.kind', 'fleet')
            ->assertJsonPath('report.status', 'pending')->assertJsonPath('proposal', null)->json();
        $this->assertStringContainsString('inventario PDF', $result['answer']);
        $this->assertStringNotContainsString('servidor-alpha', $result['answer']);
        $this->assertStringContainsString('/equipos/inventarios/', $result['report']['status_url']);
        $this->assertDatabaseCount('fleet_reports', 1);
        Bus::assertDispatched(GenerateFleetReport::class, 1);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_single_device_report_is_queued_by_accented_name_even_when_offline(): void
    {
        Bus::fake();
        $this->forbidModel();
        $admin = $this->admin();
        $device = $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $this->device('Computador del Doctor', 'DESKTOP-RAVF7FN');
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Generá un informe de Netbook Felix',
        ])->assertOk()->assertJsonPath('report.kind', 'device')
            ->assertJsonPath('proposal', null)->json();
        $this->assertStringContainsString('/informes/', $result['report']['status_url']);
        $this->assertDatabaseHas('device_reports', ['device_id' => $device->id, 'status' => 'pending']);
        $this->assertDatabaseCount('fleet_reports', 0);
        Bus::assertDispatched(GenerateDeviceReport::class, 1);
    }

    public function test_ambiguous_target_cannot_generate_report_for_arbitrary_device(): void
    {
        Bus::fake();
        $this->forbidModel();
        $admin = $this->admin();
        $this->device('Netbook Félix', 'DESKTOP-01');
        $this->device('Netbook Félix', 'DESKTOP-02');
        $reply = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Quiero un reporte de Netbook Felix',
        ])->assertOk()->json();
        $this->assertArrayNotHasKey('report', $reply);
        $reply = $reply['answer'];
        $this->assertStringContainsString('varias computadoras', $reply);
        $this->assertDatabaseCount('device_reports', 0);
        Bus::assertNotDispatched(GenerateDeviceReport::class);
    }

    public function test_hallucinated_inventory_without_tool_call_is_blocked_and_replaced_by_real_data(): void
    {
        $this->fakeInventedText();
        $admin = $this->admin();
        $first = $this->device('Computador del Doctor', 'DESKTOP-RAVF7FN');
        $second = $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $reply = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Decime qué características tienen mis computadoras registradas',
        ])->assertOk()->assertJsonPath('proposal', null)->json();
        $this->assertStringNotContainsString('servidor-alpha', $reply['answer']);
        $this->assertStringNotContainsString('workstation-01', $reply['answer']);
        $this->assertCount(2, $reply['evidence'][0]['data']['devices']);
        $this->assertEqualsCanonicalizing([$first->uuid, $second->uuid],
            array_column($reply['evidence'][0]['data']['devices'], 'uuid'));
    }

    public function test_model_cannot_replace_verified_tool_results_with_fictitious_inventory(): void
    {
        $admin = $this->admin();
        $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $stub = new class implements ChatModel {
            public int $calls = 0;
            public function complete(array $messages, array $tools): array
            {
                $this->calls++;
                if ($this->calls === 1) return [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'read-devices-1', 'type' => 'function',
                        'function' => ['name' => 'find_devices', 'arguments' => '{"sort":"name"}'],
                    ]],
                ];
                return ['content' => 'Hay 5 equipos registrados: servidor-alpha, servidor-beta y workstation-01.', 'tool_calls' => []];
            }
        };
        $this->app->instance(ChatModel::class, $stub);
        $reply = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Decime qué características tienen mis computadoras registradas',
        ])->assertOk()->json();
        $this->assertSame(2, $stub->calls);
        $this->assertStringNotContainsString('servidor-alpha', $reply['answer']);
        $this->assertStringContainsString('1 equipo', $reply['answer']);
        $this->assertSame('Netbook Félix', $reply['evidence'][0]['data']['devices'][0]['name']);
    }

    public function test_python_deployment_question_uses_registered_hardware_without_declaring_compatibility(): void
    {
        $this->forbidModel();
        $admin = $this->admin();
        $this->device('Netbook Félix', 'DESKTOP-CN1BDGF');
        $reply = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora podrá ejecutar un servidor Python?',
        ])->assertOk()->assertJsonPath('evidence.0.kind', 'processors')->json('answer');
        $this->assertStringContainsString('Flask', $reply);
        $this->assertStringContainsString('No existe un mínimo universal', $reply);
    }

    public function test_general_technical_question_does_not_get_rejected_or_invent_inventory(): void
    {
        $this->fakeInventedText();
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Es mejor Laravel o Node.js para un servidor?',
        ])->assertOk();
        $this->assertNotEmpty($response->json('answer'));
        $this->assertStringNotContainsString('está especializado en equipos', $response->json('answer'));
        $this->assertStringNotContainsString('servidor-alpha', $response->json('answer'));
        $this->assertSame([], $response->json('evidence'));
        $this->assertDatabaseCount('fleet_reports', 0);
    }

    public function test_flask_followup_is_accepted_as_technical_scope(): void
    {
        $this->fakeInventedText();
        $admin = $this->admin();
        $reply = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Quiero desplegar Flask',
            'history' => [['role' => 'user', 'content' => '¿Qué computadora podrá ejecutar un servidor Python?']],
        ])->assertOk()->json('answer');
        $this->assertStringNotContainsString('está especializado en equipos', $reply);
        $this->assertStringNotContainsString('servidor-alpha', $reply);
    }

    public function test_general_technology_question_uses_one_model_call_without_intent_round_trip(): void
    {
        $admin = $this->admin();
        $stub = new class implements ChatModel {
            public int $calls = 0;
            public function complete(array $messages, array $tools): array
            {
                $this->calls++;
                return ['content' => 'Laravel es un framework de PHP; Node.js es un entorno de ejecución de JavaScript. La elección depende del proyecto.', 'tool_calls' => []];
            }
        };
        $this->app->instance(ChatModel::class, $stub);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Es mejor Laravel o Node.js para un servidor?',
        ])->assertOk()->json('answer');
        $this->assertSame(1, $stub->calls);
        $this->assertStringContainsString('Laravel', $answer);
        $this->assertStringContainsString('Node.js', $answer);
    }

    public function test_explanatory_report_question_does_not_generate_pdf(): void
    {
        $admin = $this->admin();
        $this->assertFalse(app(AssistantReportPlanner::class)->requestsReport('¿Qué incluye el informe PDF?'));
        $this->assertFalse(app(AssistantReportPlanner::class)->requestsReport('¿Cómo exporto un inventario?'));
        $this->assertFalse(app(AssistantReportPlanner::class)->requestsReport('Quiero saber cómo descargar un informe PDF'));
        $this->assertFalse(app(AssistantReportPlanner::class)->requestsReport('No generes el reporte, solo quiero consultar'));
        $this->assertDatabaseCount('device_reports', 0);
        $this->assertDatabaseCount('fleet_reports', 0);
    }
}
