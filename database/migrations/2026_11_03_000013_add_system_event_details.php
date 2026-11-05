<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_system_events', static function (Blueprint $table): void {
            $table->text('message')->nullable();
            $table->string('user_sid', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_system_events', static function (Blueprint $table): void {
            $table->dropColumn(['message', 'user_sid']);
        });
    }
};
