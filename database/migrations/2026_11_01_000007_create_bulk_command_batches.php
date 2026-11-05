<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_commands')) {
            throw new \RuntimeException('Falta la tabla device_commands. Ejecutá primero las migraciones anteriores.');
        }

        // Some upgraded databases have the v5.3 migration recorded, but lack
        // its batch_uuid column. Repair the schema before querying legacy data.
        // This is additive and does not change existing command identities.
        if (! Schema::hasColumn('device_commands', 'batch_uuid')) {
            Schema::table('device_commands', static function (Blueprint $table): void {
                $table->uuid('batch_uuid')->nullable();
            });
        }

        // Preserve the per-device idempotency guarantee even if a prior
        // installation created the column without its unique constraint.
        if (! Schema::hasIndex('device_commands', ['device_id', 'batch_uuid'], 'unique')) {
            Schema::table('device_commands', static function (Blueprint $table): void {
                $table->unique(['device_id', 'batch_uuid']);
            });
        }

        // The previous failed attempt may have created this table already.
        if (! Schema::hasTable('bulk_command_batches')) {
            Schema::create('bulk_command_batches', static function (Blueprint $table): void {
                $table->uuid('uuid')->primary();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 32);
                $table->char('request_hash', 64);
                $table->json('target_uuids');
                $table->timestamps();
            });
        }

        // A single query avoids one query per legacy batch. It also works
        // when the table contains no legacy commands or the column was just
        // added. Never replay old commands during this migration.
        $legacyCommands = DB::table('device_commands')
            ->join('devices', 'devices.id', '=', 'device_commands.device_id')
            ->whereNotNull('device_commands.batch_uuid')
            ->orderBy('device_commands.batch_uuid')
            ->orderBy('device_commands.id')
            ->get([
                'device_commands.batch_uuid as batch_uuid',
                'device_commands.action as action',
                'device_commands.user_id as user_id',
                'devices.uuid as device_uuid',
            ]);

        foreach ($legacyCommands->groupBy('batch_uuid') as $uuid => $commands) {
            $targets = $commands->pluck('device_uuid')->unique()->values()->all();
            $first = $commands->first();

            // The original v5.3 request scope cannot be reconstructed.
            // Reserve its UUID with a distinct hash so a retry is rejected
            // rather than generating duplicate shutdown/restart commands.
            DB::table('bulk_command_batches')->insertOrIgnore([
                'uuid' => (string) $uuid,
                'user_id' => $first->user_id,
                'action' => $first->action,
                'request_hash' => hash('sha256', 'legacy-v5.3-'.(string) $uuid),
                'target_uuids' => json_encode($targets, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_command_batches');
        // Do not drop device_commands.batch_uuid here: migration 000006 owns
        // the column and commands may already reference existing batches.
    }
};
