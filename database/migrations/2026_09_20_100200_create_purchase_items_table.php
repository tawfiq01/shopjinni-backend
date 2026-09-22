<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_color_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->unsignedInteger('warranty_months')->nullable();
            // FIFO cost-lot tracking for quantity-based SKUs: how much of this
            // batch hasn't been consumed by a sale/transfer/return yet.
            $table->unsignedInteger('remaining_quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
