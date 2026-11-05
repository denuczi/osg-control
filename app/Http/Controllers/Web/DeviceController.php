<?php

namespace App\Http\Controllers\Web;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\DeviceViewData;
use App\Support\CommandViewData;
use App\Services\DeviceAvailability;
use App\Services\MonitorSettings;

class DeviceController
{
    public function index(Request $request, DeviceAvailability $availability): Response
    {
        $search = trim((string) $request->query('q', ''));
        $cutoff = $availability->cutoff();
        $devices = Device::query()->whereNull('revoked_at')->whereNull('archived_at')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhere('hostname', 'like', '%'.addcslashes($search, '%_\\').'%');
                });
            })->orderBy('name')->paginate(15)->withQueryString();
        return Inertia::render('Devices/Index', [
            'devices' => $devices->through(fn (Device $device) => DeviceViewData::from($device, false, $cutoff)),
            'filters' => ['q' => $search],
            'refreshSeconds' => app(\App\Services\MonitorSettings::class)->dashboardRefresh(),
        ]);
    }

    public function show(Device $device, DeviceAvailability $availability): Response
    {
        abort_if($device->revoked_at, 404);
        $device->load('inventory');
        // CommandViewData reads the related device name. Preload it to avoid
        // LazyLoadingViolationException when the history has multiple commands.
        $commands = $device->commands()->with('device')->latest()->limit(15)->get();
        return Inertia::render('Devices/Show', [
            'device' => DeviceViewData::from($device, true, $availability->cutoff()),
            'commands' => $commands->map(fn (DeviceCommand $command) => CommandViewData::from($command)),
            'refreshSeconds' => app(\App\Services\MonitorSettings::class)->dashboardRefresh(),
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        abort_if($device->revoked_at, 404);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        DB::transaction(function () use ($device, $request, $validated): void {
            $device->update(['name' => trim($validated['name'])]);
            AuditLog::query()->create(['user_id' => $request->user()->id, 'device_id' => $device->id, 'event' => 'device.renamed', 'context' => ['name' => $device->name]]);
        });
        return back()->with('success', 'Nombre actualizado.');
    }

    /** Backward-compatible full status endpoint used by previous clients. */
    public function status(Device $device, DeviceAvailability $availability, MonitorSettings $settings): JsonResponse
    {
        abort_if($device->revoked_at, 404);
        $device->load('inventory');
        $data = DeviceViewData::from($device, true, $availability->cutoff());
        $data['media_events'] = $device->mediaEvents()->orderByDesc('observed_at')->limit(20)->get()
            ->map(fn ($event) => [
                'uuid' => $event->event_uuid, 'kind' => $event->kind,
                'volume' => $event->volume,
                'observed_at' => $event->observed_at?->toIso8601String(),
            ])->all();
        $data['next_refresh_seconds'] = $settings->dashboardRefresh();
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function lightStatus(Device $device, DeviceAvailability $availability, MonitorSettings $settings): JsonResponse
    {
        abort_if($device->revoked_at, 404);
        $data = DeviceViewData::from($device, false, $availability->cutoff());
        $data['processes'] = DeviceViewData::recentProcesses($device, $data['online']);
        $data['next_refresh_seconds'] = $settings->dashboardRefresh();
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function mediaHistory(Request $request, Device $device): JsonResponse
    {
        abort_if($device->revoked_at, 404);
        $events = $device->mediaEvents()->orderByDesc('observed_at')->orderByDesc('id')
            ->paginate(50);
        return response()->json($events->through(fn ($event) => [
            'uuid' => $event->event_uuid, 'kind' => $event->kind,
            'volume' => $event->volume,
            'observed_at' => $event->observed_at?->toIso8601String(),
        ]))->header('Cache-Control', 'no-store');
    }

    public function metrics(Request $request, Device $device): JsonResponse
    {
        abort_if($device->revoked_at, 404);
        $range = $request->validate(['hours' => ['nullable', 'integer', 'in:1,6,24,48,72,168']]);
        $hours = (int) ($range['hours'] ?? 1);
        // Limit chart payload without truncating the oldest 20,000 rows of long ranges.
        // Aggregate into bounded time buckets in SQLite. Missing buckets remain absent.
        $start = now()->subHours($hours);
        // At 5-second sampling, 60-second buckets hide short demo changes.
        // Keep the graph bounded to ~360 points, including at the 1h range.
        $bucketSeconds = max(5, (int) ceil($hours * 3600 / 360));
        $samples = $device->metrics()->where('recorded_at', '>=', $start)
            ->selectRaw('MAX(recorded_at) AS recorded_at, AVG(cpu_percent) AS cpu_percent, AVG(memory_percent) AS memory_percent, AVG(network_rx_bps) AS network_rx_bps, AVG(network_tx_bps) AS network_tx_bps')
            ->groupByRaw("CAST(strftime('%s', recorded_at) AS INTEGER) / {$bucketSeconds}")
            ->orderBy('recorded_at')
            ->limit(400)->get();
        return response()->json(['next_refresh_seconds' => app(\App\Services\MonitorSettings::class)->dashboardRefresh(), 'samples' => $samples->map(fn ($m) => [
            'time' => $m->recorded_at->toIso8601String(),
            'cpu' => $m->cpu_percent,
            'memory' => $m->memory_percent,
            'rx_mbps' => $m->network_rx_bps === null ? null : round($m->network_rx_bps / 1000000, 3),
            'tx_mbps' => $m->network_tx_bps === null ? null : round($m->network_tx_bps / 1000000, 3),
        ])]);
    }

    public function command(Request $request, Device $device, DeviceAvailability $availability): RedirectResponse
    {
        abort_if($device->revoked_at, 404);
        if ($device->is_demo) {
            return back()->withErrors(['action' => 'Las acciones remotas están deshabilitadas para los equipos de demostración.']);
        }
        $data = $request->validate([
            'action' => ['required', 'in:terminate_process,shutdown,restart,show_message'],
            'pid' => ['required_if:action,terminate_process', 'nullable', 'integer', 'min:1'],
            'process_name' => ['required_if:action,terminate_process', 'nullable', 'string', 'max:160'],
            'process_started_at' => ['required_if:action,terminate_process', 'nullable', 'numeric', 'min:0'],
            'message' => ['required_if:action,show_message', 'nullable', 'string', 'max:500'],
            'confirm' => ['required', 'accepted'],
        ]);
        if (!$device->isOnline($availability->cutoff())) {
            return back()->withErrors(['action' => 'La computadora está desconectada.']);
        }
        if ($data['action'] === 'terminate_process') {
            $present = collect(DeviceViewData::recentProcesses($device, true))->contains(fn ($p) =>
                (int) ($p['pid'] ?? 0) === (int) $data['pid'] && strcasecmp((string) ($p['name'] ?? ''), $data['process_name']) === 0 && abs((float) ($p['started_at'] ?? -1) - (float) $data['process_started_at']) < 0.01
            );
            if (!$present) { return back()->withErrors(['action' => 'El proceso no coincide con los datos recientes del equipo.']); }
            $parameters = ['pid' => (int) $data['pid'], 'process_name' => $data['process_name'], 'process_started_at' => (float) $data['process_started_at']];
        } elseif ($data['action'] === 'show_message') {
            $parameters = ['message' => trim((string) $data['message'])];
            if ($parameters['message'] === '') {
                return back()->withErrors(['message' => 'El mensaje no puede estar vacío.']);
            }
        } else {
            $parameters = [];
        }
        // SQLite can contend briefly with telemetry writes. Retry only genuine
        // transaction concurrency failures; other exceptions remain visible in logs.
        DB::transaction(function () use ($device, $request, $data, $parameters): void {
            $command = DeviceCommand::query()->create([
                'uuid' => (string) Str::uuid(),
                'device_id' => $device->id,
                'user_id' => $request->user()->id,
                'action' => $data['action'],
                'parameters' => $parameters,
                'status' => 'pending',
                'expires_at' => now()->addMinutes($data['action'] === 'show_message' ? 10 : 2),
            ]);
            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'device_id' => $device->id,
                'event' => 'command.created',
                'context' => [
                    'command_uuid' => $command->uuid,
                    'action' => $command->action,
                    'parameters' => $parameters,
                ],
            ]);
        }, 3);
        return back()->with('success', 'Orden registrada. Se ejecutará cuando el agente la reciba.');
    }
}
