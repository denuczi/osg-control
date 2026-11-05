<?php

namespace App\Http\Controllers\Web;

use App\Models\FleetReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class FleetReportController
{
    public function create(Request $request, \App\Services\Reports\ReportRequestService $requests): JsonResponse
    {
        $report = $requests->fleet((int) $request->user()->id);
        return response()->json($this->view($report), 202)->header('Cache-Control', 'no-store');
    }

    public function status(FleetReport $fleetReport): JsonResponse
    {
        return response()->json($this->view($fleetReport))->header('Cache-Control', 'no-store');
    }

    public function download(FleetReport $fleetReport): BinaryFileResponse
    {
        $base = realpath(storage_path('app/private/reports/fleet'));
        $file = is_string($fleetReport->file_path) ? realpath($fleetReport->file_path) : false;
        abort_unless($fleetReport->status === 'ready' && $base !== false && $file !== false &&
            str_starts_with($file, $base.DIRECTORY_SEPARATOR) && is_file($file), 404);

        return response()->download($file, 'OSG-Nodo-Control-Inventario-'.$fleetReport->uuid.'.pdf', [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
        ]);
    }

    private function view(FleetReport $report): array
    {
        return [
            'uuid' => $report->uuid,
            'status' => $report->status,
            'error_code' => $report->error_code,
            'device_count' => $report->device_count,
            'download_url' => $report->status === 'ready' ? route('fleet-reports.download', $report) : null,
        ];
    }
}
