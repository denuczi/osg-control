<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing system_settings.value is an unsigned integer; don't change its
        // type and risk corrupting agent polling / performance limits.
        Schema::create('display_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->string('value', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('display_settings');
    }
};
