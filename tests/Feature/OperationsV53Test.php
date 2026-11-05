<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMediaEvent;
use App\Models\MetricSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OperationsV53Test extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $enabled = true): User
    {
        return User::query()->create(['name' => 'Administrador', 'email' => Str::random(10).'@example.test',
            'password' => 'password123456', 'is_admin' => $enabled]);
    }

    private function device(bool $online = true, bool $demo = false): Device
    {
        return Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC de prueba', 'hostname' => Str::random(8),
            'platform' => 'Windows 11', 'token_hash' => hash('sha256', random_bytes(32)),
            'last_seen_at' => $online ? now() : now()->subHour(), 'is_demo' => $demo,
        ]);
    }

    public function test_guest_and_regular_user_cannot_issue_bulk_commands(): void
    {
        $device = $this->device();
        $payload = ['action'=>'shutdown','batch_uuid'=>(string)Str::uuid(),'device_uuids'=>[$device->uuid],'confirm'=>true];
        $this->post('/equipos/ordenes/masivas', $payload)->assertRedirect('/login');
        $this->actingAs($this->admin(false))->post('/equipos/ordenes/masivas', $payload)->assertForbidden();
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_bulk_commands_are_created_per_device_and_audited(): void
    {
        $a = $this->device(); $b = $this->device();
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', [
            'action'=>'show_message', 'batch_uuid'=>(string)Str::uuid(), 'device_uuids'=>[$a->uuid,$b->uuid], 'message'=>'Reunión a las 16:00', 'confirm'=>true,
        ])->assertRedirect();
        $this->assertDatabaseCount('device_commands', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertSame('Reunión a las 16:00', DeviceCommand::query()->firstOrFail()->parameters['message']);
    }

    public function test_invalid_bulk_selection_is_atomic(): void
    {
        $online = $this->device(); $offline = $this->device(false);
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', [
            'action'=>'restart', 'batch_uuid'=>(string)Str::uuid(), 'device_uuids'=>[$online->uuid,$offline->uuid], 'confirm'=>true,
        ])->assertSessionHasErrors('device_uuids');
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_all_online_scope_excludes_demo_and_offline(): void
    {
        $valid = $this->device(); $this->device(false); $this->device(true, true);
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', [
            'action'=>'shutdown', 'batch_uuid'=>(string)Str::uuid(), 'all_online'=>true, 'device_uuids'=>[], 'confirm'=>true,
        ])->assertRedirect();
        $this->assertDatabaseCount('device_commands', 1);
        $this->assertSame($valid->id, DeviceCommand::query()->firstOrFail()->device_id);
    }

    public function test_message_must_be_nonempty_and_bounded(): void
    {
        $device = $this->device(); $url = '/equipos/'.$device->uuid.'/ordenes';
        $this->actingAs($this->admin())->post($url, ['action'=>'show_message','message'=>' ', 'confirm'=>true])
            ->assertSessionHasErrors('message');
        $this->actingAs($this->admin())->post($url, ['action'=>'show_message','message'=>str_repeat('x',501), 'confirm'=>true])
            ->assertSessionHasErrors('message');
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_media_history_requires_admin_and_includes_optical_events(): void
    {
        $device = $this->device();
        DeviceMediaEvent::query()->create([
            'device_id'=>$device->id, 'event_uuid'=>(string)Str::uuid(), 'kind'=>'detected',
            'observed_at'=>now(), 'received_at'=>now(),
            'volume'=>['device'=>'D:', 'mountpoint'=>'D:\\','media_class'=>'optical','removable'=>true,'total_bytes'=>0],
        ]);
        $this->get('/equipos/'.$device->uuid.'/medios')->assertRedirect('/login');
        $this->actingAs($this->admin())->getJson('/equipos/'.$device->uuid.'/medios')
            ->assertOk()->assertJsonPath('data.0.volume.media_class', 'optical');
    }

    public function test_performance_ranges_cover_full_period_without_truncating_old_samples(): void
    {
        $device = $this->device();
        foreach ([now()->subDays(6),now()->subDays(3),now()->subDay(),now()->subHour()] as $at) {
            MetricSample::query()->create(['device_id'=>$device->id, 'cpu_percent'=>15,
                'memory_percent'=>30, 'disk_percent'=>40, 'recorded_at'=>$at]);
        }
        $this->actingAs($this->admin())->getJson('/equipos/'.$device->uuid.'/metricas?hours=168')
            ->assertOk()->assertJsonCount(4, 'samples');
        $this->actingAs($this->admin())->getJson('/equipos/'.$device->uuid.'/metricas?hours=169')
            ->assertUnprocessable();
    }

    public function test_bulk_retry_with_same_key_does_not_create_more_commands(): void
    {
        $a = $this->device(); $b = $this->device();
        $key = (string)Str::uuid();
        $payload = ['action'=>'restart','batch_uuid'=>$key,'device_uuids'=>[$a->uuid,$b->uuid], 'confirm'=>true];
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', $payload)->assertRedirect();
        $this->post('/equipos/ordenes/masivas', $payload)->assertRedirect();
        $this->assertDatabaseCount('device_commands', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_optical_media_event_can_be_uploaded_by_agent_only(): void
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::query()->create([
            'uuid'=>(string)Str::uuid(), 'name'=>'PC con DVD', 'hostname'=>'PC-DVD',
            'platform'=>'Windows 10', 'token_hash'=>hash('sha256', $token),
        ]);
        $payload = ['events'=>[[
            'event_uuid'=>(string)Str::uuid(), 'observed_at'=>now()->subMinute()->toIso8601String(),
            'kind'=>'detected', 'volume'=>['device'=>'D:', 'mountpoint'=>'D:\\',
                'total_bytes'=>0, 'used_bytes'=>0, 'free_bytes'=>0, 'removable'=>true,
                'media_class'=>'optical', 'serial'=>'CD-123'],
        ]]];
        $this->postJson('/api/v1/agents/media-events/batch', $payload)->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/agents/media-events/batch', $payload)
            ->assertOk()->assertJsonCount(1, 'accepted_ids');
        $this->assertDatabaseHas('device_media_events', ['device_id'=>$device->id,'kind'=>'detected']);
    }

    public function test_shared_app_name_comes_from_configuration(): void
    {
        config(['app.name' => 'OSG Control']);
        $this->actingAs($this->admin())->get('/panel')->assertOk()->assertSee('OSG Control');
    }

    public function test_all_online_retry_does_not_add_newly_online_devices(): void
    {
        $initial = $this->device();
        $key = (string) Str::uuid();
        $payload = ['action' => 'restart', 'batch_uuid' => $key,
            'all_online' => true, 'device_uuids' => [], 'confirm' => true];
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', $payload)->assertRedirect();
        $later = $this->device();
        $this->post('/equipos/ordenes/masivas', $payload)->assertRedirect();
        $this->assertDatabaseCount('device_commands', 1);
        $this->assertSame([$initial->uuid], \App\Models\BulkCommandBatch::query()
            ->findOrFail($key)->target_uuids);
        $this->assertNotEquals($later->id, DeviceCommand::query()->firstOrFail()->device_id);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_same_batch_with_changed_action_or_targets_is_rejected(): void
    {
        $a = $this->device(); $b = $this->device();
        $key = (string) Str::uuid();
        $payload = ['action' => 'shutdown', 'batch_uuid' => $key,
            'device_uuids' => [$a->uuid], 'confirm' => true];
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', $payload)->assertRedirect();
        $this->post('/equipos/ordenes/masivas', [
            ...$payload, 'action' => 'restart',
        ])->assertSessionHasErrors('batch_uuid');
        $this->post('/equipos/ordenes/masivas', [
            ...$payload, 'device_uuids' => [$b->uuid],
        ])->assertSessionHasErrors('batch_uuid');
        $this->assertDatabaseCount('device_commands', 1);
    }

    public function test_other_administrator_cannot_replay_another_users_batch(): void
    {
        $device = $this->device();
        $payload = ['action' => 'shutdown', 'batch_uuid' => (string) Str::uuid(),
            'device_uuids' => [$device->uuid], 'confirm' => true];
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', $payload)->assertRedirect();
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', $payload)
            ->assertSessionHasErrors('batch_uuid');
        $this->assertDatabaseCount('device_commands', 1);
    }

    public function test_bulk_rejection_does_not_reserve_uuid_or_create_partial_batch(): void
    {
        $device = $this->device(false);
        $key = (string) Str::uuid();
        $this->actingAs($this->admin())->post('/equipos/ordenes/masivas', [
            'action' => 'restart', 'batch_uuid' => $key,
            'device_uuids' => [$device->uuid], 'confirm' => true,
        ])->assertSessionHasErrors('device_uuids');
        $this->assertDatabaseMissing('bulk_command_batches', ['uuid' => $key]);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_visible_device_statuses_are_bounded_and_do_not_expose_credentials(): void
    {
        $device = $this->device();
        $this->actingAs($this->admin())->getJson('/equipos/estados?uuids[0]='.$device->uuid)
            ->assertOk()->assertJsonCount(1, 'devices')
            ->assertJsonMissingPath('devices.0.token_hash');
        $this->getJson('/equipos/estados?uuids[0]=not-a-uuid')->assertUnprocessable();
    }

    public function test_dashboard_settings_queries_do_not_scale_with_device_count(): void
    {
        for ($i = 0; $i < 60; $i++) { $this->device(); }
        $this->actingAs($this->admin());
        \Illuminate\Support\Facades\DB::connection()->enableQueryLog();
        \Illuminate\Support\Facades\DB::connection()->flushQueryLog();
        $this->getJson('/panel/estado')->assertOk()->assertJsonCount(60, 'devices');
        $queries = \Illuminate\Support\Facades\DB::connection()->getQueryLog();
        \Illuminate\Support\Facades\DB::connection()->disableQueryLog();
        $settings = array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'system_settings'));
        $this->assertLessThanOrEqual(3, count($settings), 'Hay consultas repetidas a settings por equipo.');
        $this->assertLessThanOrEqual(12, count($queries), 'El dashboard debe tener consultas O(1) respecto de N.');
    }

}
