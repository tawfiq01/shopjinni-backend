<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('color_id')->constrained()->restrictOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('unit')->default('pcs');
            // null = inherit the model's/type's default; true/false = override for this exact SKU.
            $table->boolean('imei_tracking_enabled')->nullable();
            $table->unsignedInteger('warranty_months')->nullable();
            $table->unsignedInteger('reorder_level')->default(0);
            $table->decimal('selling_price_current', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_variant_id', 'color_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_colors');
    }
};
