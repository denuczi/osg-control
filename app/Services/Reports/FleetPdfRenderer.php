<?php

namespace App\Services\Reports;

use App\Services\Reports\Contracts\FleetRenderer;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FleetPdfRenderer implements FleetRenderer
{
    public function render(string $uuid, array $inventory, string $executionId): string
    {
        if (! \Illuminate\Support\Str::isUuid($uuid) || ! \Illuminate\Support\Str::isUuid($executionId)) {
            throw new RuntimeException('fleet_pdf_invalid_identifier');
        }
        $directory = storage_path('app/private/reports/fleet');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('fleet_pdf_directory_unavailable');
        }
        $htmlFile = tempnam($directory, 'fleet-');
        if ($htmlFile === false) throw new RuntimeException('fleet_pdf_temp_unavailable');
        @chmod($htmlFile, 0600);

        // Cada intento usa un archivo distinto. No puede sobrescribir la salida
        // de otro worker que esté recuperando una ejecución antigua.
        $output = $directory.'/'.$uuid.'-'.$executionId.'.pdf';
        $draft = $output.'.draft';
        try {
            $html = View::make('reports.fleet', [
                'inventory' => $inventory,
                'presenter' => new ReportPresentation((string) ($inventory['display_timezone'] ?? app(\App\Services\DisplayTimezone::class)->current())),
            ])->render();
            if (strlen($html) > (int) config('reports.fleet_max_html_bytes', 8 * 1024 * 1024)) {
                throw new RuntimeException('fleet_pdf_html_limit_exceeded');
            }
            if (file_put_contents($htmlFile, $html) === false) throw new RuntimeException('fleet_pdf_write_failed');
            unset($html);
            $process = new Process([(string) config('reports.weasyprint_binary'), $htmlFile, $draft]);
            $process->setTimeout((int) config('reports.pdf_timeout_seconds', 35));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($draft) || filesize($draft) < 500 ||
                file_get_contents($draft, false, null, 0, 5) !== '%PDF-') {
                throw new RuntimeException('fleet_pdf_render_failed');
            }
            @chmod($draft, 0600);
            if (! rename($draft, $output)) throw new RuntimeException('fleet_pdf_publish_failed');
            return $output;
        } catch (\Throwable $exception) {
            @unlink($draft);
            @unlink($output);
            throw $exception;
        } finally {
            @unlink($htmlFile);
        }
    }
}
