<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global — a plan belongs to the platform, not any one tenant, so
        // it deliberately has no company_id and isn't BelongsToCompany.
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('yearly_price', 10, 2);
            // Null = unlimited on all three.
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_products')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('trial_period_days')->default(14);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // One row per company. Deliberately does NOT use BelongsToCompany
        // (see app/Domain/Subscriptions/Models/Subscription.php) — written
        // almost exclusively by a Super Admin acting on someone else's
        // company, so company_id is always explicit rather than scoped.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->string('status')->default('trial');
            $table->string('billing_cycle')->default('monthly');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            // "Never expires" is this flag, not a null current_period_ends_at
            // — see the model docblock for why that ambiguity is unsafe.
            $table->boolean('is_lifetime')->default(false);
            $table->timestamp('payment_due_since')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            // Snapshot of the plan at the moment of payment — kept even if
            // the plan is later edited or the subscription moves to a
            // different plan.
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->decimal('amount', 10, 2);
            $table->string('billing_cycle');
            $table->string('method');
            $table->string('reference')->nullable();
            $table->timestamp('paid_at');
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('recorded_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('payment_records');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
