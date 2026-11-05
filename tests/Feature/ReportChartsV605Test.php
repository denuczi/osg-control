<?php

namespace Tests\Feature;

use App\Services\Reports\ReportCharts;
use PHPUnit\Framework\TestCase;

final class ReportChartsV605Test extends TestCase
{
    public function test_recent_hourly_measurements_produce_visible_continuous_data(): void
    {
        $svg = (new ReportCharts)->hourly([
            ['time' => '2026-11-03 07:00', 'cpu_avg' => 20],
            ['time' => '2026-11-03 08:00', 'cpu_avg' => 50],
            ['time' => '2026-11-03 09:00', 'cpu_avg' => 35],
        ], 'cpu_avg', '#2b668c', '2026-11-03T10:00:00Z');
        $this->assertSame(3, substr_count($svg, '<circle'));
        $this->assertSame(2, substr_count($svg, 'stroke-width="2.6"'));
        $this->assertStringContainsString('03/11', $svg);
    }

    public function test_missing_intervals_are_never_joined_and_no_sample_is_not_zero(): void
    {
        $svg = (new ReportCharts)->hourly([
            ['time' => '2026-11-01 08:00', 'ram_avg' => 80],
            ['time' => '2026-11-03 08:00', 'ram_avg' => 83],
        ], 'ram_avg', '#178776', '2026-11-03T10:00:00Z');
        $this->assertSame(2, substr_count($svg, '<circle'));
        $this->assertSame(0, substr_count($svg, 'stroke-width="2.6"'));
        $this->assertSame('', (new ReportCharts)->hourly([], 'cpu_avg', '#2b668c', '2026-11-03T10:00:00Z'));
    }
}
