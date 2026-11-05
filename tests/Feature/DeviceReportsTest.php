<?php

namespace Tests\Feature;

use App\Jobs\GenerateDeviceReport;
use App\Models\Device;
use App\Models\DeviceReport;
use App\Models\MetricSample;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Reports\Contracts\ConclusionWriter;
use App\Services\Reports\Contracts\PdfRenderer;
use App\Services\Reports\ReportAiContext;
use App\Services\Reports\ReportDataCollector;
use App\Services\Reports\ReportAiEvidenceCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceReportsTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::query()->create(['uuid' => (string) Str::uuid(),
            'name' => 'Notebook QA', 'hostname' => 'QA-01',
            'platform' => 'Windows 10', 'token_hash' => hash('sha256', Str::random(32))]);
    }

    private function user(bool $admin): User
    {
        return User::query()->create(['name' => 'QA', 'email' => Str::uuid().'@example.test',
            'password' => 'test-pass-12345678', 'is_admin' => $admin]);
    }

    public function test_authentication_and_admin_authorization_are_required(): void
    {
        $device = $this->device();
        $url = route('reports.create', $device);
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($this->user(false))->postJson($url)->assertForbidden();
    }

    public function test_ai_is_disabled_by_default_and_duplicate_requests_reuse_pending_report(): void
    {
        Bus::fake();
        $device = $this->device();
        $this->actingAs($this->user(true));
        $first = $this->postJson(route('reports.create', $device))->assertStatus(202)
            ->assertJsonPath('ai_status', 'disabled')->json('uuid');
        $second = $this->postJson(route('reports.create', $device))->assertStatus(202)->json('uuid');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('device_reports', 1);
        $this->assertFalse(DeviceReport::query()->firstOrFail()->ai_requested);
    }

    public function test_settings_cannot_enable_ai_without_server_credentials(): void
    {
        // Never depend on the developer's .env when testing missing credentials.
        config()->set('reports.ai.provider', 'groq');
        config()->set('reports.ai.model', 'openai/gpt-oss-20b');
        config()->set('reports.ai.groq_key', null);

        $this->actingAs($this->user(true))
            ->from('/configuracion')->put(route('reports.settings.update'), ['ai_enabled' => true])
            ->assertSessionHasErrors('ai_enabled');

        $this->assertDatabaseMissing('system_settings', ['key' => 'report_ai_conclusion_enabled', 'value' => 1]);
    }

    public function test_settings_can_enable_ai_with_configured_server_credentials(): void
    {
        // A fake key is sufficient: saving settings must not call the AI provider.
        config()->set('reports.ai.provider', 'groq');
        config()->set('reports.ai.model', 'openai/gpt-oss-20b');
        config()->set('reports.ai.groq_key', 'fake-key-for-testing-only');

        $this->actingAs($this->user(true))
            ->from('/configuracion')->put(route('reports.settings.update'), ['ai_enabled' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('system_settings', [
            'key' => 'report_ai_conclusion_enabled',
            'value' => 1,
        ]);
    }

    public function test_settings_cannot_enable_ai_without_a_model(): void
    {
        config()->set('reports.ai.provider', 'groq');
        config()->set('reports.ai.model', null);
        config()->set('reports.ai.groq_key', 'fake-key-for-testing-only');

        $this->actingAs($this->user(true))
            ->from('/configuracion')->put(route('reports.settings.update'), ['ai_enabled' => true])
            ->assertSessionHasErrors('ai_enabled');

        $this->assertDatabaseMissing('system_settings', ['key' => 'report_ai_conclusion_enabled', 'value' => 1]);
    }

    public function test_collector_includes_all_source_domains_even_when_no_pdf_exists(): void
    {
        $device = $this->device();
        MetricSample::query()->create(['device_id' => $device->id,
            'cpu_percent' => 95, 'memory_percent' => 85, 'disk_percent' => 40,
            'recorded_at' => now()->subHour(), 'received_at' => now()]);
        $data = app(ReportDataCollector::class)->collect($device, CarbonImmutable::now());
        $this->assertSame(1, $data['metrics']['samples']);
        $this->assertSame(95.0, (float) $data['metrics']['daily'][0]['cpu_max']);
        $this->assertArrayHasKey('network_usage_daily', $data);
        $this->assertArrayHasKey('system_events', $data);
        $this->assertArrayHasKey('diagnostic', $data);
        $this->assertArrayHasKey('command_outcomes', $data);
    }

    public function test_independent_ai_evidence_includes_older_events_not_in_pdf_window(): void
    {
        $device = $this->device();
        \App\Models\DeviceSystemEvent::query()->create([
            'device_id' => $device->id, 'event_uuid' => (string) Str::uuid(),
            'channel' => 'System', 'record_id' => 503, 'event_id' => 153,
            'provider' => 'disk', 'level' => 2,
            'observed_at' => now()->subDays(20), 'received_at' => now()->subDays(20),
        ]);
        $until = CarbonImmutable::now();
        $pdfData = app(ReportDataCollector::class)->collect($device, $until);
        $independent = app(ReportAiEvidenceCollector::class)->collect($device, $until);
        $this->assertSame(0, $pdfData['system_events']['total']);
        $this->assertSame(1, $independent['window_90_days']['event_count']);
        $this->assertSame(153, (int) $independent['window_90_days']['event_types'][0]['event_id']);
    }

    public function test_ai_context_excludes_credentials_and_hardware_serial_without_losing_metrics(): void
    {
        $context = app(ReportAiContext::class)->fromRaw([
            'device' => ['token_hash' => 'secret', 'hostname' => 'sensitive', 'cpu_percent' => 96],
            'diagnostic' => ['hardware' => ['data' => ['Serial' => '12345', 'BatteryPercent' => 74]]],
            'metrics' => ['daily' => [['cpu_avg' => 36]]],
            'system_events' => ['total' => 200, 'recent' => []],
        ]);
        $this->assertSame(96, $context['device']['cpu_percent']);
        $this->assertSame(200, $context['system_events']['total']);
        $this->assertArrayNotHasKey('token_hash', $context['device']);
        $this->assertArrayNotHasKey('Serial', $context['diagnostic']['hardware']['data']);
    }

    public function test_ai_failure_does_not_prevent_pdf_and_download_requires_authentication(): void
    {
        $device = $this->device();
        $user = $this->user(true);
        $path = storage_path('app/private/reports/test-'.Str::uuid().'.pdf');
        $this->app->bind(PdfRenderer::class, function () use ($path) {
            return new class($path) implements PdfRenderer {
                public function __construct(private string $path) {}
                public function render(string $uuid, array $raw, ?string $conclusion): string
                {
                    if ($conclusion !== null) throw new \RuntimeException('Should fall back without AI');
                    return $this->path;
                }
            };
        });
        $this->app->bind(ConclusionWriter::class, fn () => new class implements ConclusionWriter {
            public function generate(array $raw): string { throw new \RuntimeException('No provider'); }
        });
        SystemSetting::query()->updateOrCreate(['key' => 'report_ai_conclusion_enabled'], ['value' => 1]);
        $report = DeviceReport::query()->create([
            'uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'user_id' => $user->id,
            'status' => 'pending', 'ai_requested' => true, 'ai_status' => 'pending',
            'period_from' => now()->subDays(7), 'period_to' => now(),
        ]);
        GenerateDeviceReport::dispatchSync($report->id);
        $report->refresh();
        $this->assertSame('ready', $report->status);
        $this->assertSame('unavailable', $report->ai_status);
        $this->getJson(route('reports.status', $report))->assertUnauthorized();
        // No fake file on disk -> a 404, never a public static URL.
        $this->actingAs($user)->get(route('reports.download', $report))->assertNotFound();
    }
}
