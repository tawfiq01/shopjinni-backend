<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_color_id')->constrained()->restrictOnDelete();
            $table->foreignId('imei_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            // Snapshotted at sale time (FIFO batch average, or the specific
            // IMEI unit's own cost) — never recomputed from later prices.
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('profit', 14, 2);
            // Primary cost batch this line drew from (traceability only —
            // a quantity-based line may have drawn from more than one FIFO
            // batch; unit_cost above is the accurate weighted-average cost
            // across whichever batches were actually consumed).
            $table->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
