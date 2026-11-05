<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantActionPolicy;
use App\Domain\AI\AssistantAudit;
use App\Domain\AI\AssistantRuntime;
use App\Domain\AI\Infrastructure\ChatCompletionModel;
use App\Models\AiActionProposal;
use App\Models\Device;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Reports\ReportSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantHardeningV630Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'qa-model',
            'reports.ai.groq_key' => 'test-token-only',
        ]);
        return User::query()->create(['name' => 'Admin', 'email' => Str::uuid().'@example.test',
            'password' => 'test-only-password', 'is_admin' => true]);
    }

    private function device(string $name, bool $online = true): Device
    {
        return Device::query()->create(['uuid' => (string) Str::uuid(), 'name' => $name,
            'hostname' => 'PC-'.strtoupper(Str::random(8)), 'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()), 'is_demo' => false,
            'last_seen_at' => $online ? now() : now()->subHours(3)]);
    }

    private function proposal(User $user, Device $device): AiActionProposal
    {
        return AiActionProposal::query()->create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'batch_uuid' => (string) Str::uuid(), 'action' => 'show_message',
            'device_uuids' => [$device->uuid], 'message' => 'Mantenimiento',
            'status' => 'pending', 'expires_at' => now()->addMinutes(2),
        ]);
    }

    public function test_cancel_is_persistent_idempotent_and_prevents_confirmation(): void
    {
        $admin = $this->admin();
        $proposal = $this->proposal($admin, $this->device('PC A'));
        $this->actingAs($admin)->postJson("/asistente/propuestas/{$proposal->uuid}/cancelar")
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->actingAs($admin)->postJson("/asistente/propuestas/{$proposal->uuid}/cancelar")
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('ai_action_proposals', ['uuid' => $proposal->uuid, 'status' => 'cancelled']);
        $this->assertDatabaseCount('device_commands', 0);
        $this->actingAs($admin)->postJson("/asistente/propuestas/{$proposal->uuid}/confirmar", ['confirm' => true])
            ->assertStatus(409);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_other_admin_cannot_cancel_someone_elses_proposal(): void
    {
        $admin = $this->admin();
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test',
            'password' => 'test', 'is_admin' => true]);
        $proposal = $this->proposal($admin, $this->device('PC A'));
        $this->actingAs($other)->postJson("/asistente/propuestas/{$proposal->uuid}/cancelar")->assertNotFound();
        $this->assertDatabaseHas('ai_action_proposals', ['uuid' => $proposal->uuid, 'status' => 'pending']);
    }

    public function test_disabling_assistant_blocks_confirmation_without_creating_commands(): void
    {
        $admin = $this->admin();
        $proposal = $this->proposal($admin, $this->device('PC A'));
        config(['assistant.enabled' => false]);
        $this->actingAs($admin)->postJson("/asistente/propuestas/{$proposal->uuid}/confirmar", ['confirm' => true])
            ->assertStatus(409);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_llm_cannot_change_restart_into_shutdown_or_switch_devices(): void
    {
        $this->admin();
        $first = $this->device('PC A');
        $second = $this->device('PC B');
        $policy = app(AssistantActionPolicy::class);
        $this->assertSame('accion_no_coincide_con_solicitud', $policy->rejection(
            'Reinicia PC A', ['action' => 'shutdown', 'device_uuids' => [$first->uuid]],
        ));
        $this->assertSame('destinatarios_no_verificados', $policy->rejection(
            'Reinicia PC A', ['action' => 'restart', 'device_uuids' => [$second->uuid]],
        ));
        $this->assertSame('alcance_no_autorizado', $policy->rejection(
            'Reinicia PC A', ['action' => 'restart', 'all_online' => true, 'device_uuids' => []],
        ));
        $this->assertSame('destinatarios_no_verificados', $policy->rejection(
            'Reinicia PC B', ['action' => 'restart', 'device_uuids' => [$first->uuid]], $first->uuid,
        ));
        $this->assertNull($policy->rejection(
            'Reinicia PC A', ['action' => 'restart', 'device_uuids' => [$first->uuid]],
        ));
    }

    public function test_malicious_model_tool_cannot_turn_restart_into_shutdown(): void
    {
        $admin = $this->admin();
        $device = $this->device('PC A');
        $this->app->instance(\App\Domain\AI\Contracts\ChatModel::class, new class($device->uuid) implements \App\Domain\AI\Contracts\ChatModel {
            public function __construct(private readonly string $uuid) {}
            public function complete(array $messages, array $tools): array
            {
                return ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'unsafe', 'type' => 'function',
                    'function' => ['name' => 'prepare_bulk_action', 'arguments' => json_encode([
                        'action' => 'shutdown', 'device_uuids' => [$this->uuid], 'message' => '',
                    ], JSON_THROW_ON_ERROR)],
                ]]];
            }
        });
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', ['question' => 'Reinicia PC A'])
            ->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('no pude verificar', $answer);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_runtime_settings_are_separate_for_local_and_remote_and_validate(): void
    {
        $admin = $this->admin();
        $path = '/configuracion/asistente';
        $remote = ['total_timeout_seconds' => 35, 'provider_timeout_seconds' => 10,
            'max_provider_calls' => 3, 'max_output_tokens' => 1500];
        $this->actingAs($admin)->put($path, $remote)->assertRedirect();
        $this->assertDatabaseHas('system_settings', ['key' => 'assistant_remote_total_timeout_seconds', 'value' => 35]);
        $this->actingAs($admin)->put($path, [...$remote, 'provider_timeout_seconds' => 90])
            ->assertSessionHasErrors('provider_timeout_seconds');
        $this->assertDatabaseHas('system_settings', ['key' => 'assistant_remote_provider_timeout_seconds', 'value' => 10]);
        config(['reports.ai.provider' => 'local']);
        $this->actingAs($admin)->put($path, [...$remote, 'total_timeout_seconds' => 100])->assertRedirect();
        $this->assertDatabaseHas('system_settings', ['key' => 'assistant_local_total_timeout_seconds', 'value' => 100]);
        $this->assertDatabaseHas('system_settings', ['key' => 'assistant_remote_total_timeout_seconds', 'value' => 35]);
    }

    public function test_local_inference_only_accepts_loopback_and_needs_no_api_key(): void
    {
        $this->admin();
        $settings = app(ReportSettings::class);
        config(['reports.ai.provider' => 'local', 'reports.ai.model' => 'llama3.1:8b',
            'reports.ai.local_url' => 'http://127.0.0.1:11434/v1',
            'reports.ai.groq_key' => null, 'reports.ai.openai_key' => null]);
        $this->assertTrue($settings->configured());
        $this->assertTrue($settings->safeLocalUrl('http://[::1]:11434/v1'));
        foreach (['http://10.0.0.4:11434/v1', 'http://169.254.169.254/v1',
            'http://localhost:11434/v1', 'http://127.0.0.1:11434/v1?secret=1'] as $url) {
            $this->assertFalse($settings->safeLocalUrl($url));
        }
        Http::fake(['http://127.0.0.1:11434/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Prueba local'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 9],
        ], 200)]);
        $result = app(ChatCompletionModel::class)->complete([['role' => 'user', 'content' => 'Prueba']], []);
        $this->assertSame('Prueba local', $result['content']);
        $this->assertSame(20, app(AssistantRuntime::class)->stats()['input_tokens_reported'] + app(AssistantRuntime::class)->stats()['output_tokens_reported']);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:11434/v1/chat/completions'
            && ! $request->hasHeader('Authorization') && isset($request['max_tokens']));
    }

    public function test_budget_caps_calls_and_audit_records_only_usage_numbers(): void
    {
        $this->admin();
        foreach (['max_provider_calls' => 1, 'total_timeout_seconds' => 20, 'provider_timeout_seconds' => 5] as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => 'assistant_remote_'.$key], ['value' => $value]);
        }
        $budget = app(AssistantRuntime::class);
        $this->assertNull($budget->stats()['input_tokens_reported']);
        $this->assertNull($budget->stats()['output_tokens_reported']);
        $budget->reserveCall();
        $budget->recordUsage(['prompt_tokens' => 14, 'completion_tokens' => 3]);
        $this->expectException(\RuntimeException::class);
        $budget->reserveCall();
    }

    public function test_like_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        $admin = $this->admin();
        $matching = $this->device('LAB_%_QA');
        $this->device('LAB-OTHER-QA');
        $result = app(\App\Domain\AI\Tools\FleetTools::class)->execute(
            'find_devices', ['search' => '%_'], (int) $admin->id,
        );
        $this->assertSame(1, $result['result']['total_matching']);
        $this->assertSame($matching->uuid, $result['result']['devices'][0]['uuid']);
    }

    public function test_month_in_shutdown_history_means_calendar_month_not_last_30_days(): void
    {
        $this->admin();
        $device = $this->device('LAB-01');
        \Illuminate\Support\Carbon::setTestNow('2026-11-04 12:00:00');
        try {
            \Illuminate\Support\Facades\DB::table('device_system_events')->insert([
                'device_id' => $device->id,
                'event_uuid' => (string) Str::uuid(),
                'channel' => 'system', 'record_id' => 54321,
                'event_id' => 41, 'provider' => 'Microsoft-Windows-Kernel-Power',
                'level' => 2, 'observed_at' => '2026-10-28 12:00:00',
                'received_at' => '2026-11-04 12:00:00',
            ]);
            $telemetry = app(\App\Domain\AI\Tools\FleetTelemetry::class);
            $this->assertSame(0, $telemetry->shutdowns('month')['unclean_incidents']);
            $this->assertSame(1, $telemetry->shutdowns('30d')['unclean_incidents']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_exceeding_model_budget_returns_a_human_readable_error_without_orders(): void
    {
        $admin = $this->admin();
        SystemSetting::query()->updateOrCreate(['key' => 'assistant_remote_max_provider_calls'], ['value' => 1]);
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'wrong', 'type' => 'function',
                    'function' => ['name' => 'find_devices', 'arguments' => '{}'],
                ]]], 'finish_reason' => 'tool_calls',
            ]],
        ], 200)]);
        // This question first invokes the semantic router. The mocked response
        // doesn't match the mandatory router tool; the main model call then
        // exceeds the configured single-call budget and fails closed.
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué ocurrió con la comunicación de los equipos la semana pasada?',
        ])->assertStatus(503)->assertJsonFragment(['error' =>
            'La consulta alcanzó los límites de tiempo o llamadas configurados para el asistente. Revisá «Configuración → Rendimiento del asistente IA» o realizá una pregunta más concreta.',
        ]);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_demo_availability_is_consistent_with_online_query(): void
    {
        $this->admin();
        $demo = $this->device('Demo');
        $demo->update(['is_demo' => true]);
        $availability = app(\App\Services\DeviceAvailability::class);
        $this->assertFalse($availability->isOnline($demo->fresh(), $availability->cutoff()));
        $this->assertSame(0, $availability->online(Device::query(), $availability->cutoff())->count());
    }
}
