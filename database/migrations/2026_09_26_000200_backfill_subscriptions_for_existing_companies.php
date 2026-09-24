<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Any company that already exists by the time this runs (the
     * originally-migrated "Fair Telecom" data, or anyone who registered
     * before this migration shipped) gets a lifetime Premium subscription
     * — not a hardcoded company-name lookup (that pattern doesn't
     * generalize once real companies can already exist), and naturally a
     * no-op on a fresh RefreshDatabase test run since no companies exist
     * yet at migration time.
     */
    public function up(): void
    {
        $premiumPlanId = DB::table('subscription_plans')->where('slug', 'premium')->value('id');

        if (! $premiumPlanId) {
            return;
        }

        $now = now();

        $companyIds = DB::table('companies')
            ->whereNotIn('id', DB::table('subscriptions')->select('company_id'))
            ->pluck('id');

        foreach ($companyIds as $companyId) {
            DB::table('subscriptions')->insert([
                'company_id' => $companyId,
                'plan_id' => $premiumPlanId,
                'status' => 'active',
                'billing_cycle' => 'yearly',
                'trial_ends_at' => null,
                'current_period_ends_at' => null,
                'is_lifetime' => true,
                'payment_due_since' => null,
                'cancelled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data-only migration; nothing to structurally reverse.
    }
};
