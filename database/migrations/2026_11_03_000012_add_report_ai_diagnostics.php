<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_reports', static function (Blueprint $table): void {
            $table->string('ai_error_code', 48)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_reports', static function (Blueprint $table): void {
            $table->dropColumn('ai_error_code');
        });
    }
};
