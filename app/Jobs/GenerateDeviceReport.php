<?php

namespace App\Jobs;

use App\Models\DeviceReport;
use App\Services\Reports\Contracts\ConclusionWriter;
use App\Services\Reports\ReportDataCollector;
use App\Services\Reports\ReportAiEvidenceCollector;
use App\Services\Reports\ReportSettings;
use App\Services\Reports\Contracts\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateDeviceReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 75;

    public function __construct(public readonly int $reportId) {}

    public function handle(ReportDataCollector $collector, ReportAiEvidenceCollector $evidence, ReportSettings $settings, ConclusionWriter $writer, PdfRenderer $renderer): void
    {
        $report = DeviceReport::query()->with('device')->find($this->reportId);
        if (! $report || $report->status !== 'pending') return;
        if (! $report->device || $report->device->revoked_at) {
            $report->update(['status' => 'failed', 'error_code' => 'device_unavailable', 'finished_at' => now()]);
            return;
        }
        // Timestamp represents the actual snapshot execution, not queue waiting time.
        $until = CarbonImmutable::now();
        $report->update(['status' => 'processing', 'period_from' => $until->subDays(7), 'period_to' => $until]);
        $pdf = null;
        try {
            $raw = $collector->collect($report->device, $until);
            $conclusion = null;
            if ($report->ai_requested && ! $settings->enabled()) {
                // The admin disabled AI after this job was queued.
                $report->update(['ai_status' => 'disabled']);
            } elseif ($report->ai_requested) {
                try {
                    $aiInput = $raw;
                    // Independent look-back: incidents older than the PDF's seven-day
                    // view can still be considered by the model.
                    $aiInput['independent_evidence'] = $evidence->collect($report->device,
                        $until);
                    $conclusion = $writer->generate($aiInput);
                    $report->update(['ai_status' => 'completed', 'ai_error_code' => null]);
                } catch (Throwable $e) {
                    $reason = $e->getMessage();
                    $code = preg_match('/^ai_[a-z0-9_]+$/', $reason) ? $reason : 'ai_unexpected_error';
                    $report->update(['ai_status' => 'unavailable', 'ai_error_code' => $code]);
                    // Never log tokens, full payloads, prompts, or provider responses.
                    Log::warning('Report AI unavailable', ['report_id' => $report->id,
                        'exception_type' => get_class($e),
                        'reason' => $code]);
                }
            }
            $pdf = $renderer->render($report->uuid, $raw, $conclusion);
            $report->update(['status' => 'ready', 'file_path' => $pdf,
                'finished_at' => now(), 'error_code' => null]);
        } catch (Throwable $e) {
            if ($pdf !== null && str_starts_with($pdf, storage_path('app/private/reports').'/')) {
                @unlink($pdf);
            }
            $report->update(['status' => 'failed', 'error_code' => 'generation_failed',
                'finished_at' => now()]);
            Log::error('Device report failed', ['report_id' => $report->id,
                'exception_type' => get_class($e)]);
        }
    }
}
