<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data migration, not a seeder — `php artisan migrate` alone (no
     * `db:seed`) is what actually runs in production, and both plan
     * provisioning (CompanyProvisioningService) and the next migration
     * (backfilling existing companies) need these rows to already exist.
     * firstOrCreate-by-slug keeps this safe to run more than once.
     */
    public function up(): void
    {
        $now = now();

        DB::table('subscription_plans')->updateOrInsert(
            ['slug' => 'basic'],
            [
                'name' => 'Basic',
                'monthly_price' => 800,
                'yearly_price' => 8000,
                'max_users' => 3,
                'max_products' => 200,
                'max_branches' => 1,
                'trial_period_days' => 14,
                'features' => json_encode([]),
                'is_active' => true,
                'sort_order' => 1,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('subscription_plans')->updateOrInsert(
            ['slug' => 'premium'],
            [
                'name' => 'Premium',
                'monthly_price' => 2000,
                'yearly_price' => 20000,
                'max_users' => null,
                'max_products' => null,
                'max_branches' => null,
                'trial_period_days' => 14,
                'features' => json_encode(['advanced_reports']),
                'is_active' => true,
                'sort_order' => 2,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('subscription_plans')->whereIn('slug', ['basic', 'premium'])->delete();
    }
};
