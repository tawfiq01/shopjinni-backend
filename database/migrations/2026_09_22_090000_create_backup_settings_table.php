<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Singleton table — a single row (id=1) holds the whole backup
        // configuration, mirroring how the rest of the app avoids a
        // separate settings-key-value table for a handful of fields.
        Schema::create('backup_settings', function (Blueprint $table) {
            $table->id();
            $table->text('google_access_token')->nullable();
            $table->text('google_refresh_token')->nullable();
            $table->timestamp('google_token_expires_at')->nullable();
            $table->string('google_account_email')->nullable();
            $table->string('drive_folder_id')->nullable();
            $table->string('drive_folder_name')->nullable();
            // Plain "HH:mm" string rather than a SQL TIME column — avoids
            // needing a Carbon time-only cast (Laravel doesn't have one)
            // for what's only ever compared as a string against now()->format('H:i').
            $table->string('backup_time', 5)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->date('last_scheduled_run_date')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->string('last_backup_status')->nullable();
            $table->text('last_backup_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_settings');
    }
};
