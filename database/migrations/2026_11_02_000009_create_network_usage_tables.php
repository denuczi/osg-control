<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('network_usage_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('bucket_uuid');
            $table->string('interface_name', 120);
            $table->string('boot_id', 64);
            $table->timestamp('hour_utc');
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('rx_bytes');
            $table->unsignedBigInteger('tx_bytes');
            $table->unsignedInteger('observed_seconds');
            $table->timestamp('updated_at');
            $table->unique(['device_id', 'bucket_uuid']);
            $table->unique(['device_id', 'boot_id', 'interface_name', 'hour_utc'], 'network_bucket_source_unique');
            $table->index(['device_id', 'hour_utc']);
        });
        Schema::create('network_usage_hourly', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestamp('hour_utc');
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('observed_seconds')->default(0);
            $table->unique(['device_id', 'hour_utc']);
            $table->index('hour_utc');
        });
        Schema::create('network_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('observed_seconds')->default(0);
            $table->unique(['device_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_usage_daily');
        Schema::dropIfExists('network_usage_hourly');
        Schema::dropIfExists('network_usage_buckets');
    }
};
