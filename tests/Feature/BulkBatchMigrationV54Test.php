<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BulkBatchMigrationV54Test extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_batch_is_backfilled_once_and_repeated_up_preserves_it(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'migration-test@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);

        $devices = [];
        foreach (['PC-01', 'PC-02'] as $hostname) {
            $devices[] = Device::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $hostname,
                'hostname' => $hostname,
                'platform' => 'Windows 11',
                'token_hash' => hash('sha256', random_bytes(32)),
            ]);
        }

        $batchUuid = (string) Str::uuid();
        foreach ($devices as $device) {
            DB::table('device_commands')->insert([
                'uuid' => (string) Str::uuid(),
                'batch_uuid' => $batchUuid,
                'device_id' => $device->id,
                'user_id' => $admin->id,
                'action' => 'shutdown',
                'parameters' => '{}',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_01_000007_create_bulk_command_batches.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('bulk_command_batches', 1);
        $row = DB::table('bulk_command_batches')->where('uuid', $batchUuid)->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertSame('shutdown', $row->action);
        $this->assertSame(hash('sha256', 'legacy-v5.3-'.$batchUuid), $row->request_hash);
        $this->assertSame(
            array_map(static fn (Device $device): string => $device->uuid, $devices),
            json_decode($row->target_uuids, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseCount('device_commands', 2);
    }

    public function test_empty_history_does_not_create_batches(): void
    {
        $migration = require database_path('migrations/2026_11_01_000007_create_bulk_command_batches.php');
        $migration->up();
        $this->assertDatabaseCount('bulk_command_batches', 0);
    }

    public function test_missing_batch_uuid_column_is_restored_without_losing_commands(): void
    {
        // Simulates a v5.3 database where migration 000006 was recorded as
        // complete but the actual SQLite schema lacks its batch_uuid column.
        Schema::table('device_commands', static function (Blueprint $table): void {
            $table->dropUnique(['device_id', 'batch_uuid']);
            $table->dropColumn('batch_uuid');
        });
        $this->assertFalse(Schema::hasColumn('device_commands', 'batch_uuid'));

        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'migration-recovery@example.test',
            'password' => 'password123456', 'is_admin' => true,
        ]);
        $device = Device::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'PC', 'hostname' => 'PC-RECOVERY',
            'platform' => 'Windows 10', 'token_hash' => hash('sha256', random_bytes(32)),
        ]);
        DB::table('device_commands')->insert([
            'uuid' => (string) Str::uuid(), 'device_id' => $device->id,
            'user_id' => $admin->id, 'action' => 'restart', 'parameters' => '{}',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_01_000007_create_bulk_command_batches.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('device_commands', 'batch_uuid'));
        $this->assertTrue(Schema::hasIndex('device_commands', ['device_id', 'batch_uuid'], 'unique'));
        $this->assertDatabaseCount('device_commands', 1);
        $this->assertDatabaseCount('bulk_command_batches', 0);
    }

    public function test_missing_unique_index_is_restored_without_duplicate_batches(): void
    {
        Schema::table('device_commands', static function (Blueprint $table): void {
            $table->dropUnique(['device_id', 'batch_uuid']);
        });
        $this->assertFalse(Schema::hasIndex('device_commands', ['device_id', 'batch_uuid'], 'unique'));

        $migration = require database_path('migrations/2026_11_01_000007_create_bulk_command_batches.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('device_commands', ['device_id', 'batch_uuid'], 'unique'));
        $this->assertDatabaseCount('bulk_command_batches', 0);
    }

}
