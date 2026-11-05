<?php

namespace Tests\Feature;

use App\Services\Reports\ReportCharts;
use App\Services\Reports\ReportPresentation;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class DeviceReportPresentationTest extends TestCase
{
    public function test_pdf_template_displays_local_dates_network_and_translated_commands(): void
    {
        $raw = [
            'windows_support' => app(\App\Services\Reports\WindowsSupportAssessment::class)->evaluate([
                'Caption' => 'Windows 10 Pro', 'EditionId' => 'Professional', 'Build' => 19045,
                'EsuCommercialStatus' => 'not_detected', 'EsuLicensedYears' => [],
            ], new \DateTimeImmutable('2026-11-03')),
            'meta' => ['captured_at' => '2026-11-03T12:09:30Z',
                'period_start' => '2026-10-27T12:09:30Z',
                'period_end' => '2026-11-03T12:09:30Z',
                'display_timezone' => 'America/Argentina/Buenos_Aires', 'interval_seconds' => 60],
            'device' => ['name' => 'Equipo de prueba', 'hostname' => 'PC-QA',
                'platform' => 'Windows 10', 'last_seen_at' => '2026-11-03T10:03:35Z',
                'cpu_percent' => 42, 'memory_percent' => 75, 'memory_total_bytes' => null],
            'inventory' => [
                'processor' => ['name' => 'Intel CPU', 'physical_cores' => 2, 'logical_processors' => 4],
                'volumes' => [],
                'network' => ['default_gateway' => '192.168.0.1',
                    'dns_servers' => ['1.1.1.1', '8.8.8.8'], 'ssid' => null,
                    'interfaces' => [['name' => 'Ethernet', 'up' => false, 'link_speed_mbps' => null,
                        'ipv4' => [['address' => '169.254.1.2']], 'ipv6' => [], 'mac' => null]],
                    'profiles' => [['interface' => 'WiFi', 'name' => 'Michardo',
                        'category' => null, 'ipv4_connectivity' => '4']],
                    'neighbors' => [['ip' => '192.168.0.111', 'mac' => '3C-7C-3F-15-0A-9A', 'state' => '5']]],
            ],
            'diagnostic' => ['captured_at' => '2026-11-03T10:03:35Z',
                'hardware' => ['status' => 'unavailable', 'data' => null],
                'disks' => ['status' => 'unavailable', 'data' => null],
                'security' => ['status' => 'unavailable', 'data' => null]],
            'metrics' => ['daily_local' => [['time' => '2026-11-03', 'cpu_avg' => 30, 'ram_avg' => 65, 'samples' => 3]],
                'hourly' => [['time' => '2026-11-03 07:00', 'cpu_avg' => 30, 'ram_avg' => 65],
                    ['time' => '2026-11-03 08:00', 'cpu_avg' => 35, 'ram_avg' => 60]],
                'samples' => 3, 'cpu_p95_approx' => 42, 'ram_p95_approx' => 75,
                'gaps_over_3_intervals' => 0],
            'system_events' => ['total' => 1, 'recent' => [[
                'observed_at' => '2026-11-03T12:09:30Z', 'event_id' => 1074,
                'record_id' => 321, 'provider' => 'User32', 'channel' => 'System',
                'level' => 4, 'message' => 'Reiniciado por C:\\Windows\\test.exe <script>alert(1)</script>',
                'user_sid' => 'S-1-5-21-100',
            ]], 'distribution' => []],
            'network_usage_daily' => [], 'media_events' => ['total' => 0],
            'command_outcomes' => [['action' => 'show_message', 'status' => 'completed', 'total' => 1]],
        ];

        $html = View::make('reports.device', ['report' => $raw,
            'conclusion' => null, 'chartMaker' => new ReportCharts,
            'presenter' => new ReportPresentation])->render();

        self::assertStringContainsString('3 de noviembre de 2026, 09:09 h', $html);
        self::assertStringContainsString('Puerta de enlace predeterminada', $html);
        self::assertStringContainsString('Servidores DNS', $html);
        self::assertStringContainsString('1.1.1.1 · 8.8.8.8', $html);
        self::assertStringContainsString('Fin del soporte publicado', $html);
        self::assertStringContainsString('14/10/2025', $html);
        self::assertStringContainsString('Esta consulta no verifica inscripciones ESU de consumidores', $html);
        self::assertStringNotContainsString('La familia Windows 10 finalizó su soporte general', $html);
        self::assertStringContainsString('Mostrar mensaje al usuario', $html);
        self::assertStringContainsString('Reinicio o apagado solicitado', $html);
        self::assertStringContainsString('S-1-5-21-100', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('<circle', $html);
        self::assertStringContainsString('Completada', $html);
        self::assertStringNotContainsString('dns_servers', $html);
        self::assertStringNotContainsString('default_gateway', $html);
        self::assertStringNotContainsString('"ipv4":', $html);
        self::assertStringNotContainsString('show_message', $html);
        self::assertStringNotContainsString('2026-11-03T12:09:30', $html);

        // LTSC vigente: el PDF no debe mostrar avisos generales de fin de soporte.
        $raw['windows_support'] = app(\App\Services\Reports\WindowsSupportAssessment::class)->evaluate([
            'Caption' => 'Windows 10 Enterprise LTSC 2021',
            'EditionId' => 'EnterpriseS',
            'Build' => 19044,
        ], new \DateTimeImmutable('2026-11-03'));
        $htmlLtsc = View::make('reports.device', [
            'report' => $raw, 'conclusion' => null,
            'chartMaker' => new ReportCharts, 'presenter' => new ReportPresentation,
        ])->render();
        self::assertStringContainsString('dentro de su ciclo de soporte publicado', $htmlLtsc);
        self::assertStringNotContainsString('La familia Windows 10 finalizó su soporte general', $htmlLtsc);
    }
}
