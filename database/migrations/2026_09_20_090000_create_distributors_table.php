<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('mobile');
            $table->string('alt_mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributors');
    }
};
