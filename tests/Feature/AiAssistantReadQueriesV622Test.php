<?php

namespace Tests\Feature;

use App\Domain\AI\AssistantIntent;
use App\Domain\AI\Contracts\ChatModel;
use App\Domain\AI\Tools\FleetTools;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiAssistantReadQueriesV622Test extends TestCase
{
    use RefreshDatabase;

    private function enable(): User
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'qa-test-model',
            'reports.ai.groq_key' => 'qa-key-not-real',
        ]);
        // En consultas deterministas no debe contactarse a ningún LLM.
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('Unexpected model call for deterministic inventory query');
            }
        });
        return User::query()->create([
            'name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'testing-password', 'is_admin' => true,
        ]);
    }

    private function offlineDevice(string $name, int $ramGiB, int $memoryMb): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name, 'hostname' => $name,
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now()->subHour(),
            'last_sampled_at' => now()->subHour(),
            'memory_total_bytes' => $ramGiB * (1024 ** 3),
            'processes_sampled_at' => now()->subHour(),
            'processes' => [
                ['pid' => 120, 'name' => 'chrome.exe', 'memory_mb' => $memoryMb],
                ['pid' => 340, 'name' => 'explorer.exe', 'memory_mb' => 100],
            ],
        ]);
    }

    public function test_registered_count_and_names_include_offline_devices(): void
    {
        $admin = $this->enable();
        $first = $this->offlineDevice('DESKTOP-01', 4, 250);
        $second = $this->offlineDevice('NETBOOK-02', 8, 350);
        $answer = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'En equipos ¿cuántos hay registrados?',
        ])->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.data.registered', 2)
            ->assertJsonPath('evidence.0.data.online', 0)
            ->assertJsonPath('evidence.1.data.total_matching', 2)
            ->json();
        $this->assertCount(2, $answer['evidence'][1]['data']['devices']);
        $this->assertEqualsCanonicalizing([$first->uuid, $second->uuid], array_column($answer['evidence'][1]['data']['devices'], 'uuid'));
        $this->assertStringContainsString('2 equipos registrados', $answer['answer']);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_maximum_ram_uses_latest_registered_memory_even_if_everyone_is_offline(): void
    {
        $admin = $this->enable();
        $this->offlineDevice('DESKTOP-01', 4, 250);
        $best = $this->offlineDevice('NETBOOK-02', 8, 350);
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora tiene más RAM?',
        ])->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.data.devices.0.uuid', $best->uuid)
            ->assertJsonPath('evidence.0.data.devices.0.ram_gib', 8)
            ->assertJsonPath('evidence.0.data.devices.0.online', false);
        $this->assertStringContainsString('8,0 GiB', $response->json('answer'));
        $this->assertStringContainsString('desconectado', $response->json('answer'));
    }

    public function test_global_process_ranking_compares_multiple_offline_snapshots_without_creating_an_order(): void
    {
        $admin = $this->enable();
        $first = $this->offlineDevice('DESKTOP-01', 4, 250);
        $second = $this->offlineDevice('NETBOOK-02', 8, 650);
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Cuáles procesos en las computadoras ocupan más RAM?',
        ])->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.kind', 'fleet_processes')
            ->assertJsonPath('evidence.0.data.devices_with_samples', 2)
            ->assertJsonPath('evidence.0.data.fleet_top_processes.0.device_uuid', $second->uuid)
            ->assertJsonPath('evidence.0.data.fleet_top_processes.0.memory_mb', 650);
        $result = $response->json();
        $this->assertCount(4, $result['evidence'][0]['data']['fleet_top_processes']);
        $this->assertEqualsCanonicalizing([$first->uuid, $second->uuid], array_values(array_unique(array_column($result['evidence'][0]['data']['fleet_top_processes'], 'device_uuid'))));
        $this->assertStringNotContainsString('No preparé ninguna orden', $result['answer']);
        $this->assertDatabaseCount('ai_action_proposals', 0);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_follow_up_process_comparison_does_not_require_device_online(): void
    {
        $admin = $this->enable();
        $this->offlineDevice('DESKTOP-01', 4, 250);
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'De esos, ¿cuáles son los procesos más demandantes?',
            'history' => [['role' => 'assistant', 'content' => 'Hay un equipo registrado.']],
        ])->assertOk()->assertJsonPath('evidence.0.kind', 'fleet_processes');
        $this->assertStringContainsString('capturas', $response->json('answer'));
    }

    public function test_explicit_online_scope_is_respected_but_not_assumed(): void
    {
        $admin = $this->enable();
        $this->offlineDevice('OFFLINE-MOST-RAM', 32, 900);
        $online = $this->offlineDevice('ONLINE-LESS-RAM', 8, 100);
        $online->update(['last_seen_at' => now()]);

        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora conectada tiene más RAM?',
        ])->assertOk()->assertJsonPath('evidence.0.data.total_matching', 1)
            ->assertJsonPath('evidence.0.data.devices.0.uuid', $online->uuid);

        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Qué computadora tiene más RAM?',
        ])->assertOk()->assertJsonPath('evidence.0.data.total_matching', 2)
            ->assertJsonPath('evidence.0.data.devices.0.ram_gib', 32);
    }

    public function test_action_tool_is_not_advertised_for_read_only_questions(): void
    {
        $guard = app(AssistantIntent::class);
        $this->assertFalse($guard->permitsActions('¿Qué procesos ocupan más memoria RAM?'));
        $this->assertFalse($guard->permitsActions('¿Qué computadora tiene más RAM?'));
        $this->assertFalse($guard->permitsActions('¿Por qué se apaga esta computadora?'));
        $this->assertTrue($guard->permitsActions('Apagá ese equipo'));
        $this->assertTrue($guard->permitsActions('Avisá a LAB-PC-01'));
        $definitions = app(FleetTools::class)->definitions(false);
        $this->assertNotContains('prepare_bulk_action', array_column(array_column($definitions, 'function'), 'name'));
        $this->assertContains('fleet_top_processes', array_column(array_column($definitions, 'function'), 'name'));
    }

    public function test_ordinal_reference_cannot_become_an_unverified_shutdown_target(): void
    {
        $admin = $this->enable();
        $this->offlineDevice('LAB-01', 4, 100);
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => 'Apagá la primera',
        ])->assertOk()->assertJsonPath('proposal', null)
            ->assertJsonPath('evidence.0.kind', 'devices');
        $this->assertStringContainsString('No preparé ninguna orden', $response->json('answer'));
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_read_only_request_never_exposes_or_runs_action_tool_even_if_model_invents_a_call(): void
    {
        $admin = $this->enable();
        $this->offlineDevice('LAB-01', 4, 100);
        $stub = new class implements ChatModel {
            public int $calls = 0;
            public bool $actionWasExposed = false;

            public function complete(array $messages, array $tools): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    $this->actionWasExposed = in_array('prepare_bulk_action',
                        array_column(array_column($tools, 'function'), 'name'), true);
                    return [
                        'role' => 'assistant', 'content' => null,
                        'tool_calls' => [[
                            'id' => 'invented_action', 'type' => 'function',
                            'function' => [
                                'name' => 'prepare_bulk_action',
                                'arguments' => '{"action":"shutdown","all_online":true}',
                            ],
                        ]],
                    ];
                }
                return ['role' => 'assistant', 'content' => 'No tengo una medición comprobable.'];
            }
        };
        $this->app->instance(ChatModel::class, $stub);
        $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Cuál tiene el disco más lleno?',
        ])->assertOk()->assertJsonPath('proposal', null);
        $this->assertFalse($stub->actionWasExposed);
        $this->assertDatabaseCount('device_commands', 0);
        $this->assertDatabaseCount('ai_action_proposals', 0);
    }

    public function test_fleet_process_ranking_uses_batches_not_queries_per_device(): void
    {
        $this->enable();
        for ($i = 0; $i < 45; $i++) {
            $this->offlineDevice('LAB-'.$i, 8, 100 + $i);
        }
        $selects = 0;
        \Illuminate\Support\Facades\DB::listen(static function ($query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) $selects++;
        });
        $result = app(FleetTools::class)->execute('fleet_top_processes', [], 1)['result'];
        $this->assertSame(45, $result['devices_with_samples']);
        $this->assertCount(15, $result['fleet_top_processes']);
        $this->assertLessThanOrEqual(6, $selects, 'Las capturas no deben consultarse una por equipo.');
    }

    public function test_global_process_comparison_reports_missing_snapshots_without_inventing_processes(): void
    {
        $admin = $this->enable();
        $device = $this->offlineDevice('LAB-01', 4, 250);
        $device->update(['processes' => [], 'processes_sampled_at' => null]);
        $response = $this->actingAs($admin)->postJson('/asistente/consultar', [
            'question' => '¿Cuáles procesos consumen más RAM?',
        ])->assertOk()->assertJsonPath('evidence.0.data.devices_with_samples', 0)
            ->assertJsonPath('evidence.0.data.fleet_top_processes', []);
        $this->assertStringContainsString('no tienen capturas de procesos', $response->json('answer'));
    }
}
