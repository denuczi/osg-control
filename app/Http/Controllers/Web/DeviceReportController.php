<?php

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Models\DeviceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DeviceReportController
{
    public function create(Request $request, Device $device, \App\Services\Reports\ReportRequestService $requests): JsonResponse
    {
        $report = $requests->device((int) $request->user()->id, $device);
        return response()->json($this->view($report), 202)->header('Cache-Control', 'no-store');
    }

    public function status(DeviceReport $report): JsonResponse
    {
        $this->allow($report);
        return response()->json($this->view($report))->header('Cache-Control', 'no-store');
    }

    public function download(DeviceReport $report): BinaryFileResponse
    {
        $this->allow($report);
        abort_unless($report->status === 'ready' && is_string($report->file_path) &&
            str_starts_with($report->file_path, storage_path('app/private/reports').'/') &&
            is_file($report->file_path), 404);
        // Stable filename, no request-controlled path or response name.
        return response()->download($report->file_path, 'OSG-Nodo-Control-'.$report->device->uuid.'.pdf',
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    private function allow(DeviceReport $report): void
    {
        $report->loadMissing('device');
        abort_if($report->device?->revoked_at, 404);
        abort_if(! $report->device, 404);
    }

    private function view(DeviceReport $report): array
    {
        return ['uuid' => $report->uuid, 'status' => $report->status,
            'ai_status' => $report->ai_status,
            'ai_error_code' => $report->ai_error_code,
            'error_code' => $report->error_code,
            'download_url' => $report->status === 'ready' ? route('reports.download', $report) : null];
    }
}
