<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_model_id')->constrained()->restrictOnDelete();
            $table->string('ram')->nullable();
            $table->string('storage')->nullable();
            $table->string('extra_spec')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_model_id', 'ram', 'storage', 'extra_spec'], 'product_variants_unique_spec');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
