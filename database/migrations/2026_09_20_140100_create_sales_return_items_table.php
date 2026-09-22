<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('imei_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('condition', ['returned', 'damaged'])->default('returned');
            // Whether this brought the unit(s) back into sellable stock.
            $table->boolean('restocked');
            $table->decimal('refund_amount', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
    }
};
