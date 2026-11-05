<?php

namespace Tests\Feature;

use App\Services\Reports\ReportShutdownAssessment;
use App\Services\Reports\ReportPresentation;
use PHPUnit\Framework\TestCase;

class ReportShutdownAssessmentV608Test extends TestCase
{
    public function test_distinguishes_requested_reboots_from_one_unclean_incident(): void
    {
        $events = [
            $this->event(1074, 'User32', '2026-11-03T13:41:00Z'),
            $this->event(41, 'Microsoft-Windows-Kernel-Power', '2026-11-03T12:16:00Z'),
            $this->event(6008, 'EventLog', '2026-11-03T12:17:00Z'),
            $this->event(6006, 'EventLog', '2026-11-03T13:42:00Z'),
        ];
        $result = (new ReportShutdownAssessment)->summarize($events);
        self::assertSame(1, $result['requested_restarts_or_shutdowns']);
        self::assertSame(1, $result['unclean_incidents']);
        self::assertSame(2, $result['unclean_event_records']);
        self::assertSame(1, $result['clean_shutdown_signals']);
    }

    public function test_repeated_unclean_events_are_not_hidden_and_unrecognized_sources_are_ignored(): void
    {
        $events = [
            $this->event(41, 'Microsoft-Windows-Kernel-Power', '2026-11-03T10:00:00Z'),
            $this->event(41, 'Microsoft-Windows-Kernel-Power', '2026-11-03T10:02:00Z'),
            $this->event(6008, 'NotEventLog', '2026-11-03T10:03:00Z'),
            $this->event(6008, 'EventLog', '2026-11-03T10:04:00Z'),
        ];
        $result = (new ReportShutdownAssessment)->summarize($events);
        self::assertSame(2, $result['unclean_incidents']);
        self::assertSame(3, $result['unclean_event_records']);
    }

    public function test_pdf_titles_distinguish_clean_and_unclean_shutdowns(): void
    {
        $presenter = new ReportPresentation;
        self::assertSame('Apagado correcto registrado', $presenter->eventTitle($this->event(6006, 'EventLog', '2026-11-03T12:00:00Z')));
        self::assertSame('Inicio de apagado registrado', $presenter->eventTitle($this->event(13, 'Microsoft-Windows-Kernel-General', '2026-11-03T12:00:00Z')));
    }

    private function event(int $id, string $provider, string $at): array
    {
        return ['event_id' => $id, 'provider' => $provider, 'channel' => 'System', 'observed_at' => $at];
    }
}
