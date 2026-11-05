<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('hardware');
            $table->json('disks');
            $table->json('security');
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
        });
        Schema::create('device_system_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_uuid');
            $table->string('channel', 16);
            $table->unsignedBigInteger('record_id');
            $table->unsignedInteger('event_id');
            $table->string('provider', 120);
            $table->unsignedTinyInteger('level')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('received_at');
            $table->unique(['device_id', 'event_uuid']);
            $table->index(['device_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_system_events');
        Schema::dropIfExists('device_diagnostics');
    }
};
