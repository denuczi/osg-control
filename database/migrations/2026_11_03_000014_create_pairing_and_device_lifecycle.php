<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });

        Schema::table('device_system_events', function (Blueprint $table): void {
            $table->index(['device_id', 'event_id', 'observed_at'], 'events_device_id_time');
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['event', 'created_at'], 'audit_event_time');
        });

        Schema::create('device_pairing_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('device_uuid')->unique();
            $table->string('hostname', 120);
            $table->string('platform', 120);
            $table->string('source_ip', 45)->nullable();
            $table->string('token_hash', 64);
            $table->text('verification_code_encrypted');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['source_ip', 'status', 'expires_at'], 'pairing_source_status_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairing_requests');
        Schema::table('device_system_events', function (Blueprint $table): void {
            $table->dropIndex('events_device_id_time');
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_event_time');
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
