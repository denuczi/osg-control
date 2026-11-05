<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use DateTimeZone;

/** Pure SVG charts for WeasyPrint. Never fills gaps or invents measurements. */
final class ReportCharts
{
    public function hourly(array $series, string $key, string $color, string $until, string $timezone = \App\Services\DisplayTimezone::DEFAULT_ZONE): string
    {
        if (! preg_match('/^#[0-9a-fA-F]{6}$/D', $color)) {
            return '';
        }
        try {
            $end = new DateTimeImmutable($until);
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable) {
            return '';
        }
        $endTime = $end->getTimestamp();
        $startTime = $endTime - 7 * 86400;
        $points = [];
        foreach ($series as $row) {
            $stamp = (string) ($row['time'] ?? '');
            if (! preg_match('/^\d{4}-\d\d-\d\d \d\d:00$/D', $stamp)) {
                continue;
            }
            try {
                $timestamp = (new DateTimeImmutable($stamp, new DateTimeZone('UTC')))->getTimestamp();
            } catch (\Throwable) {
                continue;
            }
            $value = $row[$key] ?? null;
            if ($timestamp < $startTime || $timestamp > $endTime || ! is_numeric($value) ||
                ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 100) {
                continue;
            }
            $points[$timestamp] = (float) $value;
        }
        if ($points === []) {
            return '';
        }
        ksort($points, SORT_NUMERIC);
        $first = (int) array_key_first($points);
        $last = (int) array_key_last($points);
        // With less than two days of measurements, show a clearly identified
        // zoomed timeline instead of putting a tiny dot at the edge of seven days.
        $zoom = $last - $first < 2 * 86400;
        if ($zoom) {
            $span = max(12 * 3600, $last - $first + 4 * 3600);
            $startTime = $first - 2 * 3600;
            $endTime = $startTime + $span;
        }
        $width = 480;
        $axis = '';
        $grid = '';
        foreach ([0, 25, 50, 75, 100] as $percent) {
            $y = 140 - $percent;
            $grid .= sprintf('<line x1="35" x2="515" y1="%d" y2="%d" stroke="#e5eaf0"/><text x="28" y="%d" text-anchor="end" font-size="9" fill="#64748b">%d</text>', $y, $y, $y + 3, $percent);
        }
        for ($i = 0; $i <= 4; $i++) {
            $time = (int) round($startTime + ($endTime - $startTime) * $i / 4);
            $local = (new DateTimeImmutable('@'.$time))->setTimezone($zone);
            $label = $zoom ? $local->format('d/m H:i') : $local->format('d/m');
            $axis .= sprintf('<text x="%.1f" y="162" text-anchor="middle" font-size="10" fill="#64748b">%s</text>', 35 + $width * $i / 4, $label);
        }
        $segments = '';
        $circles = '';
        $previous = null;
        foreach ($points as $timestamp => $value) {
            $x = 35 + $width * ($timestamp - $startTime) / max(1, $endTime - $startTime);
            $y = 140 - $value;
            if ($previous !== null && $timestamp - $previous[0] <= 2 * 3600) {
                $segments .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="2.6" stroke-linecap="round"/>', $previous[1], $previous[2], $x, $y, $color);
            }
            $circles .= sprintf('<circle cx="%.1f" cy="%.1f" r="3.2" fill="%s"/>', $x, $y, $color);
            $previous = [$timestamp, $x, $y];
        }
        $title = $zoom ? 'Detalle horario de los días medidos' : 'Promedios horarios de siete días';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 540 174" role="img" aria-label="'.$title.'">'.$grid.$segments.$circles.$axis.'</svg>';
    }

    public function description(array $metrics): string
    {
        $hours = $metrics['hourly'] ?? [];
        if (count($hours) < 2) {
            return 'Datos insuficientes para establecer una tendencia: '.(count($hours) === 1 ? 'solo existe una hora con mediciones.' : 'no hay mediciones horarias.');
        }
        $first = strtotime((string) ($hours[0]['time'] ?? '').' UTC');
        $last = strtotime((string) ($hours[count($hours) - 1]['time'] ?? '').' UTC');
        if ($first !== false && $last !== false && $last - $first < 2 * 86400) {
            return 'Detalle horario ampliado del período realmente observado. No representa siete días completos.';
        }
        return 'Promedios horarios de los últimos siete días. Las interrupciones de medición no se conectan artificialmente.';
    }

}
