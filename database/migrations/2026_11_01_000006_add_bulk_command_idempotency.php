<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->uuid('batch_uuid')->nullable();
            $table->unique(['device_id', 'batch_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->dropUnique(['device_id', 'batch_uuid']);
            $table->dropColumn('batch_uuid');
        });
    }
};
