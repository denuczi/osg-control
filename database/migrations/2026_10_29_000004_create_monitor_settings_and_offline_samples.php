<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->unsignedInteger('value');
            $table->timestamps();
        });
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->index();
        });
        // Legacy commands without TTL are no longer safe to deliver after reconnect.
        DB::table('device_commands')->where('status', 'pending')->update(['expires_at' => now()]);
        Schema::table('metric_samples', function (Blueprint $table): void {
            $table->uuid('sample_uuid')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unique(['device_id', 'sample_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
        Schema::table('metric_samples', function (Blueprint $table): void {
            $table->dropUnique(['device_id', 'sample_uuid']);
            $table->dropColumn(['sample_uuid', 'received_at']);
        });
        Schema::dropIfExists('system_settings');
    }
};
