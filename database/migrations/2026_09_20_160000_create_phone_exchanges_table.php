<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_exchanges', function (Blueprint $table) {
            $table->id();
            // The new phone going out, recorded as a normal sale.
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            // The old phone's cost-basis batch, coming into stock the same
            // way a purchase would (so it can be resold and costed via FIFO).
            $table->foreignId('old_purchase_item_id')->constrained('purchase_items')->restrictOnDelete();
            $table->foreignId('old_imei_unit_id')->nullable()->constrained('imei_units')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('exchange_value', 12, 2);
            // new phone price - exchange value; negative means the shop
            // refunded the customer the difference instead of collecting it.
            $table->decimal('price_difference', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_exchanges');
    }
};
