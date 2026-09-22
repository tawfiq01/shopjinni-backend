<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('distributor_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
