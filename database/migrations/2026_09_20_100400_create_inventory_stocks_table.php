<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_color_id')->constrained()->restrictOnDelete();
            // Cached on-hand quantity — the source of truth is stock_movements;
            // this column is maintained transactionally for fast reads.
            $table->integer('quantity')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'product_variant_color_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
