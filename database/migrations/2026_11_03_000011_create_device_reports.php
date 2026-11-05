<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->boolean('ai_requested')->default(false);
            $table->string('ai_status', 20)->default('disabled');
            $table->string('ai_provider', 24)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->timestamp('period_from');
            $table->timestamp('period_to');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        // Database-backed report workers: keep the default sync queue intact for legacy flows.
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
                $table->index(['queue', 'reserved_at']);
            });
        }
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_reports');
        // Existing jobs / failed_jobs may belong to other modules: never drop them here.
    }
};
