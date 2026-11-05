<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProcessKnowledgeAgentV638Test extends TestCase
{
    use RefreshDatabase;

    public function test_nodocontrol_agent_is_served_from_catalog_without_model_call(): void
    {
        config(['assistant.enabled' => false]);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('Local catalog lookup must not invoke the model');
            }
        });
        $admin = User::query()->create([
            'name' => 'Administrador', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password', 'is_admin' => true,
        ]);
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC A', 'hostname' => 'PC-A',
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now(), 'processes_sampled_at' => now(),
            'processes' => [['pid' => 1234, 'name' => 'NodoControlAgent.exe', 'memory_mb' => 22]],
        ]);
        $response = $this->actingAs($admin)->postJson('/equipos/'.$device->uuid.'/procesos/informacion', [
            'process' => 'nodocontrolagent.exe',
        ])->assertOk()->assertJsonPath('source', 'catalog')->assertJsonPath('known', true)->json();
        $this->assertStringContainsString('OSG Nodo Control', $response['description']);
        $this->assertStringContainsString('NodoControlAgent', $response['description']);
        $this->assertDatabaseCount('device_commands', 0);
    }
}
