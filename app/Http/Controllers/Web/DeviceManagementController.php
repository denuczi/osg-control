<?php

namespace App\Http\Controllers\Web;

use App\Models\Device;
use App\Models\DevicePairingRequest;
use App\Models\EnrollmentCode;
use App\Services\DeviceLifecycleService;
use App\Services\DevicePairingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class DeviceManagementController
{
    public function index(Request $request): Response
    {
        $pending = DevicePairingRequest::query()->where('status', 'pending')
            ->where('expires_at', '>', now())->orderByDesc('created_at')->limit(50)->get();
        $active = Device::query()->whereNull('revoked_at')->whereNull('archived_at')
            ->orderBy('name')->paginate(15, ['*'], 'active_page')->withQueryString();
        $devices = Device::query()->where(function ($query): void {
            $query->whereNotNull('revoked_at')->orWhereNotNull('archived_at');
        })->orderByDesc('updated_at')->paginate(20)->withQueryString();

        $response = Inertia::render('Devices/Management', [
            'activeDevices' => $active->through(fn (Device $device) => [
                'uuid' => $device->uuid, 'name' => $device->name,
                'hostname' => $device->hostname, 'platform' => $device->platform,
            ]),
            'pairingRequests' => $pending->map(fn ($pairing) => [
                'uuid' => $pairing->uuid, 'hostname' => $pairing->hostname, 'platform' => $pairing->platform,
                'source_ip' => $pairing->source_ip,
                'expires_at' => $pairing->expires_at->toIso8601String(),
                'failed_attempts' => $pairing->failed_attempts,
            ]),
            'inactiveDevices' => $devices->through(fn (Device $device) => [
                'uuid' => $device->uuid, 'name' => $device->name,
                'hostname' => $device->hostname, 'archived' => (bool) $device->archived_at,
                'revoked' => (bool) $device->revoked_at,
            ]),
            'legacyCode' => $request->session()->get('new_enrollment_code'),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    public function legacyCode(Request $request): RedirectResponse
    {
        $code = bin2hex(random_bytes(24));
        EnrollmentCode::query()->create([
            'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(15),
        ]);
        return redirect()->route('devices.management')->with('new_enrollment_code', $code);
    }

    public function approve(Request $request, DevicePairingRequest $pairing, DevicePairingService $service): RedirectResponse
    {
        $data = $request->validate(['verification_code' => ['required', 'digits:6']]);
        $service->approve($pairing, $data['verification_code'], $request->user()->id);
        return redirect()->route('devices.management')->with('success', 'Equipo autorizado. El agente recibirá su aprobación automáticamente.');
    }

    public function reject(Request $request, DevicePairingRequest $pairing, DevicePairingService $service): RedirectResponse
    {
        $service->reject($pairing, $request->user()->id);
        return back()->with('success', 'Solicitud rechazada.');
    }

    public function revoke(Request $request, Device $device, DeviceLifecycleService $service): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $service->revoke($device, $request->user()->id);
        return redirect()->route('devices.management')->with('success', 'Acceso revocado; el historial se conserva.');
    }

    public function archive(Request $request, Device $device, DeviceLifecycleService $service): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $service->archive($device, $request->user()->id);
        return redirect()->route('devices.management')->with('success', 'Equipo archivado y credenciales revocadas.');
    }

    public function restore(Request $request, Device $device, DeviceLifecycleService $service): RedirectResponse
    {
        $service->restoreToInventory($device, $request->user()->id);
        return redirect()->route('devices.management')->with('success', 'Equipo restaurado al inventario. El acceso sigue revocado.');
    }

    public function delete(Request $request, Device $device, DeviceLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string', 'max:100']]);
        if (! hash_equals($device->name, $data['confirm_name'])) {
            return back()->withErrors(['confirm_name' => 'Escribí exactamente el nombre del equipo para confirmar la eliminación.']);
        }
        $service->delete($device, $request->user()->id);
        return redirect()->route('devices.management')->with('success', 'Equipo y registros asociados eliminados del servidor.');
    }
}
