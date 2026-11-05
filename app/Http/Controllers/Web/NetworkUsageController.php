<?php

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Services\NetworkUsageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class NetworkUsageController
{
    public function index(): Response
    {
        return Inertia::render('NetworkUsage/Index');
    }

    public function fleet(Request $request, NetworkUsageReport $reports): JsonResponse
    {
        return response()->json($reports->data($reports->period($request)))->header('Cache-Control', 'no-store');
    }

    public function device(Request $request, Device $device, NetworkUsageReport $reports): JsonResponse
    {
        abort_if($device->revoked_at || $device->is_demo, 404);
        return response()->json($reports->data($reports->period($request), $device->id))
            ->header('Cache-Control', 'no-store');
    }
}
