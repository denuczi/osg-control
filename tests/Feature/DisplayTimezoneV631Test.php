<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\ChatModel;
use App\Models\Device;
use App\Models\User;
use App\Services\DisplayTimezone;
use App\Services\Reports\ReportDataCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DisplayTimezoneV631Test extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $isAdmin = true): User
    {
        return User::query()->create([
            'name' => 'QA', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password', 'is_admin' => $isAdmin,
        ]);
    }

    private function device(): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Netbook Félix', 'hostname' => 'DESKTOP-FELIX',
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', (string) Str::uuid()),
            'last_seen_at' => CarbonImmutable::parse('2026-11-04T01:26:00Z'),
        ]);
    }

    public function test_only_admin_may_change_display_zone_and_invalid_input_does_not_write(): void
    {
        $endpoint = '/configuracion/zona-horaria';
        $this->putJson($endpoint, ['timezone' => 'UTC'])->assertUnauthorized();
        $this->actingAs($this->admin(false))->putJson($endpoint, ['timezone' => 'UTC'])->assertForbidden();
        $this->actingAs($this->admin());
        foreach (['Bad/Timezone', 'UTC+3', "America/Argentina/Cordoba\nX"] as $bad) {
            $this->putJson($endpoint, ['timezone' => $bad])->assertUnprocessable();
        }
        $this->assertDatabaseCount('display_settings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_select_iana_zone_without_mutating_utc_storage_or_monitor_settings(): void
    {
        $this->actingAs($this->admin());
        $device = $this->device();
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('America/Argentina/Cordoba', app(DisplayTimezone::class)->current());
        $this->put('/configuracion/zona-horaria', ['timezone' => 'Europe/Madrid'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('display_settings', ['key' => 'timezone', 'value' => 'Europe/Madrid']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.timezone.updated']);
        $this->assertSame('Europe/Madrid', app(DisplayTimezone::class)->current());
        $this->assertSame('04/11/2026 02:26', app(DisplayTimezone::class)->human($device->last_seen_at));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('2026-11-04 01:26:00',
            CarbonImmutable::parse(DB::table('devices')->where('id', $device->id)->value('last_seen_at'), 'UTC')->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_utc_day_boundary_and_dst_transition_are_correct(): void
    {
        $zone = app(DisplayTimezone::class);
        $this->assertSame('03/11/2026 22:26', $zone->human(CarbonImmutable::parse('2026-11-04T01:26:00Z')));
        $zone->update('America/New_York');
        $this->assertSame('2026-03-08T01:30:00-05:00', $zone->forModel(['seen_at' => '2026-03-08T06:30:00Z'])['seen_at']);
        $this->assertSame('2026-03-08T03:30:00-04:00', $zone->forModel(['seen_at' => '2026-03-08T07:30:00Z'])['seen_at']);
        $this->assertSame('2026-11-01T01:30:00-04:00', $zone->forModel(['seen_at' => '2026-11-01T05:30:00Z'])['seen_at']);
        $this->assertSame('2026-11-01T01:30:00-05:00', $zone->forModel(['seen_at' => '2026-11-01T06:30:00Z'])['seen_at']);
        $this->assertSame('2026-03-08', $zone->forModel(['day' => '2026-03-08'])['day']);
        $this->assertSame('2026-13-88T20:10:00Z', $zone->forModel(['bad' => '2026-13-88T20:10:00Z'])['bad']);
    }

    public function test_pdf_snapshot_uses_selected_zone_and_retains_utc_instants(): void
    {
        $device = $this->device();
        app(DisplayTimezone::class)->update('Asia/Tokyo');
        $raw = app(ReportDataCollector::class)->collect($device, CarbonImmutable::parse('2026-11-04T01:26:00Z'));
        $this->assertSame('UTC', $raw['meta']['timezone']);
        $this->assertSame('Asia/Tokyo', $raw['meta']['display_timezone']);
        $this->assertSame('2026-11-04T01:26:00+00:00', $raw['device']['last_seen_at']);
        $fleet = app(\App\Services\Reports\FleetInventoryCollector::class)
            ->collect(CarbonImmutable::parse('2026-11-04T01:26:00Z'));
        $this->assertSame('Asia/Tokyo', $fleet['display_timezone']);
    }

    public function test_last_contact_is_deterministic_and_uses_real_time_in_selected_zone(): void
    {
        config([
            'assistant.enabled' => true, 'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'mock-model', 'reports.ai.groq_key' => 'mock-key',
        ]);
        $this->app->instance(ChatModel::class, new class implements ChatModel {
            public function complete(array $messages, array $tools): array
            {
                throw new \LogicException('Model must not be called for stored last contact');
            }
        });
        $device = $this->device();
        $this->actingAs($this->admin());
        $first = $this->postJson('/asistente/consultar', [
            'question' => '¿Cuándo fue visto por última vez Netbook Félix?',
        ])->assertOk()->assertJsonPath('proposal', null)->json('answer');
        $this->assertStringContainsString('03/11/2026 22:26', $first);
        $this->assertStringContainsString('America/Argentina/Cordoba', $first);
        $this->assertStringNotContainsString('01:26 UTC', $first);
        app(DisplayTimezone::class)->update('Europe/Madrid');
        $second = $this->postJson('/asistente/consultar', [
            'question' => '¿Cuándo fue la última comunicación del equipo?', 'device_uuid' => $device->uuid,
        ])->assertOk()->json('answer');
        $this->assertStringContainsString('04/11/2026 02:26', $second);
        $this->assertStringContainsString('Europe/Madrid', $second);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_model_tool_results_are_localized_without_changing_evidence(): void
    {
        config([
            'assistant.enabled' => true, 'reports.ai.provider' => 'groq',
            'reports.ai.model' => 'mock-model', 'reports.ai.groq_key' => 'mock-key',
        ]);
        $this->device();
        app(DisplayTimezone::class)->update('America/New_York');
        $fake = new class implements ChatModel {
            public array $lastMessages = [];
            private int $calls = 0;
            public function complete(array $messages, array $tools): array
            {
                $this->calls++;
                if ($this->calls === 1) return ['content' => '', 'tool_calls' => [[
                    'id' => 'test_call_1', 'type' => 'function',
                    'function' => ['name' => 'find_devices', 'arguments' => '{}'],
                ]]];
                $this->lastMessages = $messages;
                return ['content' => 'Se adjunta la evidencia del inventario.', 'tool_calls' => []];
            }
        };
        $this->app->instance(ChatModel::class, $fake);
        $this->actingAs($this->admin());
        $response = $this->postJson('/asistente/consultar', [
            'question' => 'Analizá las especificaciones disponibles de nuestras computadoras.',
        ])->assertOk()->json();
        $tool = collect($fake->lastMessages)->firstWhere('role', 'tool');
        $this->assertNotNull($tool, 'The model must receive a real tool result');
        $decoded = json_decode($tool['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('America/New_York', $decoded['display_timezone']);
        $json = $tool['content'];
        $this->assertStringContainsString('2026-11-03T20:26:00-05:00', $json);
        $this->assertStringContainsString('2026-11-04T01:26:00+00:00',
            json_encode($response['evidence'], JSON_THROW_ON_ERROR));
    }
}
