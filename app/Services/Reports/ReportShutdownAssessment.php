<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use Throwable;

/** Classifies Windows shutdown evidence without inventing incident causes. */
final class ReportShutdownAssessment
{
    /**
     * A 41 and a 6008 close in time may refer to one unclean restart.
     * Two events with the same ID remain distinct (no silent event loss).
     */
    public function summarize(array $events, bool $truncated = false): array
    {
        $requested = 0;
        $clean = 0;
        $started = 0;
        $unclean = [];
        foreach ($events as $item) {
            if (! is_array($item) || strtolower((string) ($item['channel'] ?? '')) !== 'system') {
                continue;
            }
            $id = (int) ($item['event_id'] ?? 0);
            $provider = strtolower((string) ($item['provider'] ?? ''));
            if ($id === 1074 && $provider === 'user32') {
                $requested++;
            } elseif ($id === 6006 && $provider === 'eventlog') {
                $clean++;
            } elseif ($id === 13 && $provider === 'microsoft-windows-kernel-general') {
                $started++;
            } elseif (($id === 41 && in_array($provider, ['microsoft-windows-kernel-power', 'kernel-power'], true)) ||
                ($id === 6008 && $provider === 'eventlog')) {
                try {
                    $dateText = trim((string) ($item['observed_at'] ?? ''));
                    if ($dateText === '') {
                        throw new \InvalidArgumentException('Timestamp missing');
                    }
                    $at = new DateTimeImmutable($dateText, new \DateTimeZone('UTC'));
                    $unclean[] = ['id' => $id, 'time' => $at->getTimestamp()];
                } catch (Throwable) {
                    // Invalid timestamps cannot be safely correlated, but the
                    // source event still represents a separate signal.
                    $unclean[] = ['id' => $id, 'time' => null];
                }
            }
        }
        usort($unclean, static fn ($a, $b) => ($a['time'] ?? PHP_INT_MAX) <=> ($b['time'] ?? PHP_INT_MAX));
        $groups = [];
        foreach ($unclean as $item) {
            $paired = false;
            foreach ($groups as &$group) {
                if ($item['time'] !== null && $group['time'] !== null &&
                    abs($item['time'] - $group['time']) <= 900 && ! in_array($item['id'], $group['ids'], true)) {
                    $group['ids'][] = $item['id'];
                    $paired = true;
                    break;
                }
            }
            unset($group);
            if (! $paired) {
                $groups[] = ['ids' => [$item['id']], 'time' => $item['time']];
            }
        }

        return [
            'requested_restarts_or_shutdowns' => $requested,
            'clean_shutdown_signals' => $clean,
            'shutdown_started_signals' => $started,
            'unclean_incidents' => count($groups),
            'unclean_event_records' => count($unclean),
            'recent_truncated' => $truncated,
            'interpretation' => '1074 registra una solicitud, no confirma su resultado. 13 indica inicio del cierre; 6006 evidencia cierre ordenado; 41/6008 indican un cierre previo no limpio. Las parejas cercanas 41/6008 pueden representar el mismo incidente, sin demostrar una avería física.',
        ];
    }
}
