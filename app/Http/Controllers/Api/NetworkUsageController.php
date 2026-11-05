<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Services\NetworkUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NetworkUsageController
{
    public function __invoke(Request $request, NetworkUsage $usage): JsonResponse
    {
        if (strlen($request->getContent()) > 131072) {
            return response()->json(['message' => 'Lote de consumo demasiado grande.'], 413);
        }
        $data = $request->validate([
            'buckets' => ['required', 'array', 'min:1', 'max:64'],
            'buckets.*.bucket_uuid' => ['required', 'uuid', 'distinct'],
            'buckets.*.interface_name' => ['required', 'string', 'max:120'],
            'buckets.*.boot_id' => ['required', 'string', 'max:64'],
            'buckets.*.hour_utc' => ['required', 'date'],
            'buckets.*.revision' => ['required', 'integer', 'between:1,10000000'],
            'buckets.*.rx_bytes' => ['required', 'integer', 'min:0'],
            'buckets.*.tx_bytes' => ['required', 'integer', 'min:0'],
            'buckets.*.observed_seconds' => ['required', 'integer', 'between:0,3600'],
        ]);
        /** @var Device $device */
        $device = $request->attributes->get('agent_device');
        $usage->accept($device, $data['buckets']);
        return response()->json(['ok' => true, 'accepted' => array_map(
            static fn (array $bucket): array => ['bucket_uuid' => $bucket['bucket_uuid'], 'revision' => $bucket['revision']],
            $data['buckets'],
        )]);
    }
}
