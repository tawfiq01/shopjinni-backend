<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger'); // 'manual' or 'scheduled'
            $table->string('status'); // 'success' or 'failed'
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
