<?php

namespace Tests\Unit;

use App\Services\Reports\ReportCharts;
use App\Services\Reports\ReportPresentation;
use PHPUnit\Framework\TestCase;

final class ReportPresentationTest extends TestCase
{
    public function test_utc_timestamps_are_presented_in_argentinian_time(): void
    {
        $presentation = new ReportPresentation;

        self::assertSame('3 de noviembre de 2026, 09:09 h',
            $presentation->dateTime('2026-11-03T12:09:30+00:00'));
        self::assertSame('03/11/2026 07:03',
            $presentation->shortDateTime('2026-11-03T10:03:35+00:00'));
        self::assertSame('27/10/2026 09:09 a 03/11/2026 09:09 h',
            $presentation->period('2026-10-27T12:09:30Z', '2026-11-03T12:09:30Z'));
        self::assertSame('Hora de Argentina (UTC-03:00)',
            $presentation->timezoneLabel('2026-11-03T12:09:30Z'));
        self::assertSame('03/11/2026', $presentation->calendarDay('2026-11-03'));
    }

    public function test_missing_malformed_and_non_scalar_values_are_safe(): void
    {
        $presentation = new ReportPresentation;

        self::assertSame('No disponible', $presentation->dateTime('not-a-date'));
        self::assertSame('No disponible', $presentation->dateTime(null));
        self::assertSame('No disponible', $presentation->calendarDay('2026-02-30'));
        self::assertSame('No disponible', $presentation->value(['dns_servers' => ['1.1.1.1']]));
        self::assertSame('No disponible', $presentation->percent(null));
        self::assertSame('0 %', $presentation->percent(0));
    }

    public function test_structured_network_is_mapped_to_readable_rows_without_raw_json(): void
    {
        $presentation = new ReportPresentation;
        $source = [
            'default_gateway' => '192.168.0.1', 'ssid' => null,
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
            'interfaces' => [
                ['name' => 'Ethernet', 'up' => false, 'link_speed_mbps' => null,
                    'mac' => '74-E6-E2-33-B2-D0',
                    'ipv4' => [['address' => '169.254.172.101', 'netmask' => '255.255.0.0']],
                    'ipv6' => ['fe80::1234']],
            ],
            'profiles' => [['interface' => 'WiFi', 'name' => 'Michardo',
                'category' => 'Private', 'ipv4_connectivity' => '4']],
            'neighbors' => [['ip' => '192.168.0.111', 'mac' => '3C-7C-3F-15-0A-9A', 'state' => '5']],
        ];

        $result = $presentation->network($source);
        self::assertSame('192.168.0.1', $result['gateway']);
        self::assertSame('1.1.1.1 · 8.8.8.8', $result['dns']);
        self::assertSame('Desconectada', $result['interfaces'][0]['status']);
        self::assertSame('169.254.172.101', $result['interfaces'][0]['ipv4']);
        self::assertSame('Privada', $result['profiles'][0]['category']);
        self::assertSame('Acceso a Internet', $result['profiles'][0]['connectivity']);
        self::assertSame('Accesible recientemente', $result['neighbors'][0]['state']);
        self::assertSame('4', $source['profiles'][0]['ipv4_connectivity']); // No mutation of raw evidence.
        self::assertNull($presentation->network(null));
        self::assertSame('No disponible', $presentation->network(['dns_servers' => 'malformed'])['dns']);
    }

    public function test_commands_security_and_processor_fields_use_human_labels(): void
    {
        $presentation = new ReportPresentation;

        self::assertSame('Mostrar mensaje al usuario', $presentation->action('show_message'));
        self::assertSame('Completada', $presentation->commandStatus('completed'));
        self::assertSame('Otra acción', $presentation->action('unexpected_key'));
        self::assertSame('Saludable', $presentation->health('Healthy'));
        self::assertSame('Tipo no identificado', $presentation->mediaType('Unspecified'));
        self::assertSame('Advertencia', $presentation->eventLevel(3));
        self::assertSame('Privado', $presentation->firewallProfile('Private'));
        self::assertSame('8', $presentation->processor(['physical_cores' => 8])['Núcleos físicos']);
        self::assertSame('No disponible', $presentation->processor(['max_mhz' => null])['Frecuencia máxima']);
    }

    public function test_chart_labels_are_aligned_to_local_report_day(): void
    {
        $svg = (new ReportCharts)->daily([
            ['time' => '2026-11-02', 'cpu_avg' => 44],
        ], 'cpu_avg', '#2b668c', '2026-11-03T00:30:00Z', 'America/Argentina/Buenos_Aires');

        self::assertStringContainsString('02/11', $svg);
        self::assertStringNotContainsString('03/11', $svg);
        self::assertSame(1, substr_count($svg, '<circle '));
    }
}
