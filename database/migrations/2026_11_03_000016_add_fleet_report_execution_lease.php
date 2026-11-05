<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fleet_reports', function (Blueprint $table): void {
            $table->uuid('run_token')->nullable();
            $table->timestamp('processing_started_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_reports', function (Blueprint $table): void {
            $table->dropIndex(['processing_started_at']);
            $table->dropColumn(['run_token', 'processing_started_at']);
        });
    }
};
