<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            // Running count of how many of `quantity` are currently held as
            // demo/display stock, for non-IMEI SKUs (IMEI-tracked SKUs are
            // counted live from imei_units.is_demo instead).
            $table->unsignedInteger('demo_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropColumn('demo_quantity');
        });
    }
};
