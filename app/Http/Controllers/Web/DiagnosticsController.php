<?php

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Services\Reports\WindowsSupportAssessment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DiagnosticsController
{
    public function show(Request $request, Device $device, WindowsSupportAssessment $windowsAssessment): Response
    {
        abort_if($device->revoked_at || $device->archived_at, 404);
        $validated = $request->validate([
            'event_q' => ['nullable', 'string', 'max:100'],
            'event_id' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'event_channel' => ['nullable', 'in:System,Application'],
            'event_level' => ['nullable', 'integer', 'between:1,5'],
        ]);
        $search = trim((string) ($validated['event_q'] ?? ''));
        $eventId = isset($validated['event_id']) ? (int) $validated['event_id'] : null;
        $channel = $validated['event_channel'] ?? null;
        $level = isset($validated['event_level']) ? (int) $validated['event_level'] : null;

        $device->load('diagnostic');
        $diagnostic = $device->diagnostic;
        $events = $device->systemEvents()
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, "%_\\").'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('provider', 'like', $term)
                        ->orWhere('message', 'like', $term)
                        ->orWhere('user_sid', 'like', $term);
                });
            })
            ->when($eventId !== null, fn ($query) => $query->where('event_id', $eventId))
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->when($level !== null, fn ($query) => $query->where('level', $level))
            ->orderByDesc('observed_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Diagnostics/Show', [
            'device' => ['uuid' => $device->uuid, 'name' => $device->name,
                'hostname' => $device->hostname, 'platform' => $device->platform],
            'diagnostic' => $diagnostic ? [
                'captured_at' => $diagnostic->captured_at?->toIso8601String(),
                'hardware' => $diagnostic->hardware,
                'disks' => $diagnostic->disks,
                'security' => $diagnostic->security,
            ] : null,
            'windows_support' => $windowsAssessment->evaluate(data_get($diagnostic?->security, 'data.Windows')),
            'eventFilters' => [
                'event_q' => $search,
                'event_id' => $eventId === null ? '' : (string) $eventId,
                'event_channel' => $channel ?? '',
                'event_level' => $level === null ? '' : (string) $level,
            ],
            'events' => $events->through(fn ($event) => [
                'uuid' => $event->event_uuid,
                'event_id' => $event->event_id,
                'provider' => $event->provider,
                'channel' => $event->channel,
                'observed_at' => $event->observed_at?->toIso8601String(),
                'level' => $event->level,
                'message' => $event->message,
                'user_sid' => $event->user_sid,
            ]),
        ]);
    }
}
