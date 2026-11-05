<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InertiaPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real Inertia client includes the asset version it received on its
     * initial page load. Without this header, an AJAX GET correctly triggers
     * a 409 full-reload response when a Vite manifest is present.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) (app(HandleInertiaRequests::class)->version(request()) ?? ''),
        ];
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Operador', 'email' => 'operador@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
    }

    public function test_pages_render_inertia_components_without_leaking_agent_credentials(): void
    {
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Equipo A',
            'hostname' => 'PC-A', 'platform' => 'Windows',
            'token_hash' => str_repeat('a', 64), 'last_seen_at' => now(),
        ]);
        $this->actingAs($this->admin())
            ->withHeaders($this->inertiaHeaders())
            ->get('/equipos/'.$device->uuid)
            ->assertOk()
            ->assertJsonPath('component', 'Devices/Show')
            ->assertJsonPath('props.device.uuid', $device->uuid)
            ->assertJsonMissingPath('props.device.token_hash');
    }

    public function test_dashboard_settings_and_activity_use_inertia(): void
    {
        $this->actingAs($this->admin());
        foreach (['/panel' => 'Dashboard', '/configuracion' => 'Settings/Index', '/actividad' => 'Activity/Index'] as $path => $page) {
            $this->withHeaders($this->inertiaHeaders())
                ->get($path)->assertOk()->assertJsonPath('component', $page);
        }
    }

    public function test_live_status_stays_json_after_migration(): void
    {
        $this->actingAs($this->admin())->getJson('/panel/estado')
            ->assertOk()->assertJsonStructure(['next_refresh_seconds', 'devices', 'total']);
    }
}
