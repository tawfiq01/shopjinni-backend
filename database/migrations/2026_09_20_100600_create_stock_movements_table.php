<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_color_id')->constrained()->restrictOnDelete();
            $table->foreignId('imei_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('movement_type', [
                'purchase', 'sale', 'purchase_return', 'sales_return',
                'transfer_out', 'transfer_in', 'adjustment', 'exchange_in', 'exchange_out',
            ]);
            // Signed: positive increases stock, negative decreases it.
            $table->integer('quantity_change');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->nullableMorphs('reference');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
