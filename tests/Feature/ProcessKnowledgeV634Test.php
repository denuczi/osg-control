<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use App\Services\ProcessKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProcessKnowledgeV634Test extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $admin = true): User
    {
        return User::query()->create([
            'name' => 'QA', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password', 'is_admin' => $admin,
        ]);
    }

    private function device(string $process = 'chrome.exe', string $platform = 'Windows 11'): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'LAB-01', 'hostname' => 'LAB-01',
            'platform' => $platform, 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => now(), 'processes_sampled_at' => now(),
            'processes' => [[
                'pid' => 321, 'name' => $process, 'memory_mb' => 50, 'started_at' => 1781000000,
                'can_terminate' => true,
            ]],
        ]);
    }

    private function configured(): void
    {
        config([
            'assistant.enabled' => true,
            'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'fake-test-model',
            'reports.ai.groq_key' => 'fake-test-key',
            'cache.default' => 'array',
        ]);
    }

    public function test_catalog_has_priority_and_works_without_ai(): void
    {
        $device = $this->device('CHROME.EXE');
        config(['assistant.enabled' => false]);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('The catalog must never call the model.');
            }
        });
        $this->actingAs($this->admin())
            ->postJson("/equipos/{$device->uuid}/procesos/informacion", ['process' => 'CHROME.EXE'])
            ->assertOk()->assertJsonPath('known', true)->assertJsonPath('source', 'catalog');
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_access_requires_auth_admin_and_current_process(): void
    {
        $device = $this->device();
        $url = "/equipos/{$device->uuid}/procesos/informacion";
        $this->postJson($url, ['process' => 'chrome.exe'])->assertUnauthorized();
        $this->actingAs($this->admin(false))->postJson($url, ['process' => 'chrome.exe'])->assertForbidden();
        $this->actingAs($this->admin())->postJson($url, ['process' => 'other.exe'])->assertStatus(409);
        $this->postJson($url, ['process' => '../chrome.exe'])->assertUnprocessable();
        $device->update(['revoked_at' => now()]);
        $this->postJson($url, ['process' => 'chrome.exe'])->assertNotFound();
    }

    public function test_unknown_process_when_ai_disabled_is_not_invented(): void
    {
        $device = $this->device('unknowncomponent.exe');
        config(['assistant.enabled' => false]);
        $this->actingAs($this->admin())
            ->postJson("/equipos/{$device->uuid}/procesos/informacion", ['process' => 'unknowncomponent.exe'])
            ->assertOk()->assertJsonPath('known', false)->assertJsonPath('source', 'unavailable')
            ->assertJsonPath('description', null);
    }

    public function test_ai_receives_only_process_name_and_device_windows_family(): void
    {
        $this->configured();
        $device = $this->device('MyCustomService.exe', 'Windows 10 Pro');
        $capture = new class implements ChatModel {
            public array $messages = [];
            public int $calls = 0;
            public function complete(array $messages, array $tools): array
            {
                $this->calls++;
                $this->messages = $messages;
                if ($tools !== []) throw new \LogicException('No tools should be supplied.');
                return ['content' => json_encode(['known' => true, 'description' =>
                    '## ¿Qué es MyCustomService.exe?\n\nProceso de ejemplo. El nombre no garantiza legitimidad, compruebe la firma digital y la ruta real del archivo.'])];
            }
        };
        $this->app->instance(ChatModel::class, $capture);
        $url = "/equipos/{$device->uuid}/procesos/informacion";
        $this->actingAs($this->admin())->postJson($url, ['process' => 'MyCustomService.exe'])
            ->assertOk()->assertJsonPath('known', true)->assertJsonPath('source', 'ai');
        $this->assertSame(1, $capture->calls);
        $user = json_decode($capture->messages[1]['content'], true);
        $this->assertSame('Windows 10', $user['operating_system']);
        $this->assertSame('MyCustomService.exe', $user['process']);
        $this->assertArrayNotHasKey('device_uuid', $user);
        // Repeated queries hit cache instead of calling the model twice.
        $this->postJson($url, ['process' => 'MyCustomService.exe'])->assertOk()->assertJsonPath('source', 'ai');
        $this->assertSame(1, $capture->calls);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_ai_false_is_a_valid_negative_result_and_cached(): void
    {
        $this->configured();
        $device = $this->device('CompletelyUnknownService834.exe', 'Unknown');
        $fake = new class implements ChatModel {
            public int $calls = 0;
            public function complete(array $messages, array $tools): array
            {
                $this->calls++;
                if (! str_contains($messages[1]['content'], '"operating_system":"Windows"')) {
                    throw new \LogicException('Expected generic Windows context.');
                }
                return ['content' => 'false'];
            }
        };
        $this->app->instance(ChatModel::class, $fake);
        $url = "/equipos/{$device->uuid}/procesos/informacion";
        $this->actingAs($this->admin())->postJson($url, ['process' => 'CompletelyUnknownService834.exe'])
            ->assertOk()->assertJsonPath('known', false)->assertJsonPath('source', 'none')->assertJsonPath('description', null);
        $this->postJson($url, ['process' => 'CompletelyUnknownService834.exe'])->assertOk();
        $this->assertSame(1, $fake->calls);
    }

    public function test_malformed_model_output_is_an_error_not_a_fabricated_description(): void
    {
        $this->configured();
        $device = $this->device('NotInCatalog700.exe');
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                return ['content' => 'Esta aplicación es totalmente segura, confía en mí.'];
            }
        });
        $this->actingAs($this->admin())
            ->postJson("/equipos/{$device->uuid}/procesos/informacion", ['process' => 'NotInCatalog700.exe'])
            ->assertStatus(503)->assertJsonMissingPath('description');
    }

    public function test_os_context_does_not_assume_windows_11_without_evidence(): void
    {
        $this->assertSame('Windows 11', ProcessKnowledge::osContext('Microsoft Windows 11 Pro'));
        $this->assertSame('Windows 10', ProcessKnowledge::osContext('Windows 10 Enterprise'));
        $this->assertSame('Windows', ProcessKnowledge::osContext('Windows'));
        $this->assertSame('Windows', ProcessKnowledge::osContext('Linux 6.2'));
    }
}
