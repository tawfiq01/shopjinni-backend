<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->foreignId('payment_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
