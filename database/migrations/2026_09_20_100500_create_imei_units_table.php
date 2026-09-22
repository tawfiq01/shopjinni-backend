<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imei_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_color_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->string('imei1')->unique();
            $table->string('imei2')->nullable()->unique();
            $table->string('serial_number')->nullable();
            $table->enum('status', [
                'in_stock', 'sold', 'returned', 'damaged',
                'exchanged', 'warranty_service', 'lost', 'transferred',
            ])->default('in_stock');
            $table->unsignedInteger('warranty_months')->nullable();
            $table->date('purchased_at');
            $table->date('sold_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imei_units');
    }
};
