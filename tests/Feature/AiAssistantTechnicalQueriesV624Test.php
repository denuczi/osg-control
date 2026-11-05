<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\ChatModel;
use App\Domain\AI\AssistantIntent;
use App\Domain\AI\AssistantScope;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantTechnicalQueriesV624Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'stub',
            'reports.ai.groq_key' => 'non-real-qa-key',
        ]);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('A deterministic infrastructure question must not call the AI provider');
            }
        });
        return User::query()->create([
            'name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password', 'is_admin' => true,
        ]);
    }

    private function device(string $name, bool $demo = false): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'hostname' => $name,
            'platform' => 'Windows 11 Pro', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'is_demo' => $demo, 'last_seen_at' => now()->subHour(),
        ]);
    }

    private function usage(Device $device, int $rx, int $tx): void
    {
        DB::table('network_usage_daily')->insert([
            'device_id' => $device->id,
            'day' => now((string) config('nodo.network_report_timezone'))->format('Y-m-d'),
            'rx_bytes' => $rx, 'tx_bytes' => $tx, 'observed_seconds' => 900,
        ]);
    }

    private function event(Device $device, int $id, string $provider, int $minuteAgo): void
    {
        DB::table('device_system_events')->insert([
            'device_id' => $device->id, 'event_uuid' => (string) Str::uuid(),
            'channel' => 'system', 'record_id' => $id * 100 + $minuteAgo,
            'event_id' => $id, 'provider' => $provider,
            'level' => 2, 'observed_at' => now()->subMinutes($minuteAgo)->utc()->format('Y-m-d H:i:s'),
            'received_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_about_osg_and_implementation_languages_have_specific_answers_without_model(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/asistente/consultar', ['question' => '¿Qué es OSG?'])
            ->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence', []);
        $reply = $this->postJson('/asistente/consultar', ['question' => '¿En qué lenguajes está hecho este sistem?'])
            ->assertOk()->json('answer');
        $this->assertStringContainsString('PHP', $reply);
        $this->assertStringContainsString('TypeScript', $reply);
        $this->assertStringContainsString('Python', $reply);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ai.consulted']);
    }

    public function test_network_usage_uses_offline_devices_and_real_daily_aggregates(): void
    {
        $admin = $this->admin();
        $first = $this->device('NETBOOK-01');
        $second = $this->device('LAB-02');
        $demo = $this->device('DEMO-03', true);
        $archived = $this->device('ARCHIVADO');
        $archived->update(['archived_at' => now()]);
        $this->usage($first, 2048, 1024);
        $this->usage($second, 4096, 0);
        $this->usage($demo, 1000000, 0);
        $this->usage($archived, 1000000, 0);

        $result = $this->actingAs($admin)->postJson('/asistente/consultar', ['question' => '¿Cuál es el consumo de red de las computadoras?'])
            ->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.kind', 'network')
            ->assertJsonPath('evidence.0.data.total_bytes', 7168)
            ->assertJsonPath('evidence.0.data.measured_devices', 2)
            ->assertJsonPath('evidence.0.data.registered_devices', 2)
            ->assertJsonCount(2, 'evidence.0.data.network_devices')->json();
        $this->assertStringContainsString('tráfico local', $result['answer']);
        $this->assertStringContainsString('7,00 KiB', $result['answer']);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertSame('Consumo de red de las computadoras', AuditLog::query()->where('event', 'ai.consulted')->firstOrFail()->context['topic']);
    }

    public function test_network_usage_without_samples_never_claims_zero_usage(): void
    {
        $admin = $this->admin();
        $this->device('SIN-CAPTURA');
        $result = $this->actingAs($admin)->postJson('/asistente/consultar', ['question' => 'el consumo de red?'])
            ->assertOk()->assertJsonPath('evidence.0.data.total_bytes', null)
            ->assertJsonPath('evidence.0.data.measured_devices', 0)->json();
        $this->assertStringContainsString('No hay mediciones', $result['answer']);
        $this->assertStringNotContainsString('no hubo tráfico', mb_strtolower($result['answer']));
    }

    public function test_shutdown_incident_pairs_41_and_6008_without_ignoring_offline_devices(): void
    {
        $admin = $this->admin();
        $first = $this->device('NETBOOK-FELIX');
        $this->device('LAB-SIN-INCIDENTES');
        $this->event($first, 41, 'Microsoft-Windows-Kernel-Power', 90);
        $this->event($first, 6008, 'EventLog', 88);

        $result = $this->actingAs($admin)->postJson('/asistente/consultar', ['question' => '¿Alguna compu se apagó repentinamente?'])
            ->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.kind', 'shutdowns')
            ->assertJsonPath('evidence.0.data.unclean_incidents', 1)
            ->assertJsonPath('evidence.0.data.unclean_event_records', 2)
            ->assertJsonPath('evidence.0.data.devices_with_signals', 1)
            ->assertJsonPath('evidence.0.data.shutdown_devices.0.uuid', $first->uuid)
            ->json();
        $this->assertStringContainsString('no permiten determinar', $result['answer']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_absence_of_events_is_uncertain_not_certified_shutdown_health(): void
    {
        $admin = $this->admin();
        $this->device('NETBOOK-OFFLINE');
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', ['question' => '¿Hubo apagados inesperados?'])
            ->assertOk()->assertJsonPath('evidence.0.data.unclean_incidents', 0)->json('answer');
        $this->assertStringContainsString('no demuestra', $answer);
    }

    public function test_most_used_computer_is_not_inferred_from_partial_process_snapshots(): void
    {
        $admin = $this->admin();
        $this->device('LAB-01');
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', ['question' => '¿Cuál es la computadora que más se utiliza?'])
            ->assertOk()->assertJsonPath('evidence', [])->json('answer');
        $this->assertStringContainsString('no mide tiempo de uso', $answer);
    }

    public function test_intent_scope_and_action_boundaries_remain_separate(): void
    {
        $scope = app(AssistantScope::class);
        $intent = app(AssistantIntent::class);
        $this->assertFalse($scope->allows('Me enamoré de mi prima ¿qué hago?'));
        foreach (['¿Qué es OSG?', 'el consumo de red?', '¿Hubo apagados inesperados?'] as $question) {
            $this->assertTrue($scope->allows($question));
            $this->assertNotNull($intent->readPlan($question));
            $this->assertFalse($intent->permitsActions($question));
        }
    }
}
