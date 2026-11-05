<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceDiagnostic;
use App\Services\Reports\ReportAiContext;
use App\Services\Reports\ReportDataCollector;
use App\Services\Reports\WindowsSupportAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WindowsSupportV607Test extends TestCase
{
    use RefreshDatabase;

    public function test_windows_10_pro_support_ended_but_esu_is_not_assumed_absent(): void
    {
        $result = app(WindowsSupportAssessment::class)->evaluate([
            'Caption' => 'Microsoft Windows 10 Pro', 'EditionId' => 'Professional',
            'DisplayVersion' => '22H2', 'Build' => 19045, 'UBR' => 7000,
            'EsuCommercialStatus' => 'not_detected', 'EsuLicensedYears' => [],
        ], new \DateTimeImmutable('2026-11-03T10:00:00Z'));
        $this->assertSame('support_ended', $result['status']);
        $this->assertSame('2025-10-14', $result['support_end']);
        $this->assertStringContainsString('no verifica inscripciones', $result['commercial_esu_summary']);
        $this->assertFalse($result['coverage_verified']);
    }

    public function test_ltsc_has_edition_specific_lifecycle(): void
    {
        $asOf = new \DateTimeImmutable('2026-11-03');
        $service = app(WindowsSupportAssessment::class);
        $enterprise = $service->evaluate(['EditionId' => 'EnterpriseS', 'Build' => 19044], $asOf);
        $iot = $service->evaluate(['EditionId' => 'IoTEnterpriseS', 'Build' => 19044], $asOf);
        $older = $service->evaluate(['EditionId' => 'EnterpriseS', 'Build' => 14393], $asOf);
        $this->assertSame('2027-01-12', $enterprise['support_end']);
        $this->assertSame('2032-01-13', $iot['support_end']);
        $this->assertSame('support_ended', $older['status']);
    }

    public function test_esu_year_does_not_certify_current_patch_coverage(): void
    {
        $result = app(WindowsSupportAssessment::class)->evaluate([
            'EditionId' => 'Professional', 'Build' => 19045, 'EsuCommercialStatus' => 'licensed', 'EsuLicensedYears' => [1],
        ], new \DateTimeImmutable('2026-11-03'));
        $this->assertSame([1], $result['commercial_esu_years']);
        $this->assertFalse($result['coverage_verified']);
        $this->assertStringContainsString('ya finalizó', $result['commercial_esu_summary']);
    }

    public function test_ai_context_contains_support_evidence_and_does_not_invent_event_coverage(): void
    {
        $context = app(ReportAiContext::class)->fromRaw([
            'device' => ['name' => 'PERSONAL', 'platform' => 'Windows 10'],
            'system_events' => ['recent' => [], 'distribution' => [], 'total' => 1],
            'media_events' => [],
            'windows_support' => app(WindowsSupportAssessment::class)->evaluate(['EditionId' => 'Professional', 'Build' => 19045]),
        ]);
        $this->assertArrayHasKey('windows_support', $context);
        $this->assertNull($context['event_monitoring']['coverage_percent']);
        $this->assertStringContainsString('no mide', $context['event_monitoring']['coverage_explanation']);
        $sensitive = app(ReportAiContext::class)->fromRaw(['device' => ['ProductKey' => 'SECRETKEY123'],
            'system_events' => [], 'media_events' => []]);
        $this->assertArrayNotHasKey('ProductKey', $sensitive['device']);
    }

    public function test_report_source_includes_windows_support_without_pdf_rendering(): void
    {
        $device = Device::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Prueba',
            'hostname' => 'QA', 'platform' => 'Windows 10', 'token_hash' => hash('sha256', 'qa')]);
        DeviceDiagnostic::query()->create(['device_id' => $device->id,
            'hardware' => ['status' => 'unavailable', 'data' => null],
            'disks' => ['status' => 'unavailable', 'data' => null],
            'security' => ['status' => 'ok', 'data' => ['Windows' => [
                'Caption' => 'Windows 10 Pro', 'EditionId' => 'Professional', 'Build' => 19045,
                'EsuCommercialStatus' => 'unknown', 'EsuLicensedYears' => [],
            ]]], 'captured_at' => now(), 'received_at' => now()]);
        $raw = app(ReportDataCollector::class)->collect($device, CarbonImmutable::now());
        $this->assertSame('support_ended', $raw['windows_support']['status']);
        $this->assertNull($raw['derived_indicators']['wifi_latency_ms']);
        $this->assertNull($raw['derived_indicators']['event_log_coverage_percent']);
    }
}
