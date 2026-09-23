<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            // For non-IMEI (quantity-based) SKUs: how many units in this batch
            // were purchased as demo/display stock. IMEI-tracked SKUs record
            // demo per-unit on imei_units.is_demo instead.
            $table->unsignedInteger('demo_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('demo_quantity');
        });
    }
};
