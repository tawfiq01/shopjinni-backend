<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason')->nullable();
            $table->decimal('total_refund', 14, 2)->default(0);
            // null = refunded as store credit (customer's AR balance reduced)
            // instead of paying cash/bank/MFS back out.
            $table->foreignId('refund_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
