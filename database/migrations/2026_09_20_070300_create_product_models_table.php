<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('warranty_months_default')->nullable();
            // null = inherit the product type's default; true/false = override for this model.
            $table->boolean('imei_tracking_enabled')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['brand_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_models');
    }
};
