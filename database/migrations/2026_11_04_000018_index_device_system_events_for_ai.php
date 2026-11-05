<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_system_events', function (Blueprint $table): void {
            $table->index(['event_id', 'observed_at'], 'device_system_events_ai_shutdown_idx');
        });
    }

    public function down(): void
    {
        Schema::table('device_system_events', function (Blueprint $table): void {
            $table->dropIndex('device_system_events_ai_shutdown_idx');
        });
    }
};
