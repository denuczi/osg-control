<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            foreach (['memory_used_bytes','memory_available_bytes','disk_total_bytes','disk_used_bytes','disk_free_bytes','network_rx_bps','network_tx_bps'] as $key) {
                $table->unsignedBigInteger($key)->nullable();
            }
        });
        Schema::create('device_inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('processor')->nullable();
            $table->json('volumes')->nullable();
            $table->json('network')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });
        Schema::create('device_media_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_uuid');
            $table->string('kind', 16);
            $table->json('volume');
            $table->timestamp('observed_at')->index();
            $table->timestamp('received_at');
            $table->unique(['device_id', 'event_uuid']);
        });
        Schema::table('metric_samples', function (Blueprint $table): void {
            $table->unsignedBigInteger('network_rx_bps')->nullable();
            $table->unsignedBigInteger('network_tx_bps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('metric_samples', fn (Blueprint $table) => $table->dropColumn(['network_rx_bps','network_tx_bps']));
        Schema::dropIfExists('device_media_events');
        Schema::dropIfExists('device_inventories');
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn([
            'memory_used_bytes','memory_available_bytes','disk_total_bytes','disk_used_bytes',
            'disk_free_bytes','network_rx_bps','network_tx_bps'
        ]));
    }
};
