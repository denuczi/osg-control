<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\View;
use Symfony\Component\Process\Process;
use RuntimeException;

final class ReportPdfRenderer implements \App\Services\Reports\Contracts\PdfRenderer
{
    /** Returns an absolute PDF path in private local storage, never a public URL. */
    public function render(string $uuid, array $raw, ?string $conclusion): string
    {
        $directory = storage_path('app/private/reports');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('pdf_directory_unavailable');
        }
        $htmlFile = tempnam($directory, 'render-');
        if ($htmlFile === false) throw new RuntimeException('pdf_temp_unavailable');
        $pdf = $directory.'/'.$uuid.'.pdf';
        try {
            $html = View::make('reports.device', ['report' => $raw, 'conclusion' => $conclusion,
                'chartMaker' => app(ReportCharts::class),
                'presenter' => new ReportPresentation((string) ($raw['meta']['display_timezone'] ?? app(\App\Services\DisplayTimezone::class)->current()))])->render();
            if (file_put_contents($htmlFile, $html) === false) throw new RuntimeException('pdf_temp_write_failed');
            $process = new Process([(string) config('reports.weasyprint_binary'), $htmlFile, $pdf]);
            $process->setTimeout((int) config('reports.pdf_timeout_seconds', 35));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($pdf) || filesize($pdf) < 500 ||
                file_get_contents($pdf, false, null, 0, 5) !== '%PDF-') {
                throw new RuntimeException('pdf_render_failed');
            }
            @chmod($pdf, 0600);
            return $pdf;
        } catch (\Throwable $error) {
            @unlink($pdf);
            throw $error;
        } finally {
            @unlink($htmlFile);
        }
    }
}
