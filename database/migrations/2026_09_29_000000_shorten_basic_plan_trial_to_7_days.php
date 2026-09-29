<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Basic plan's trial_period_days was seeded as 14 by
     * 2026_09_26_000100_seed_default_subscription_plans.php (now updated
     * to 7 for fresh installs) — this backfills databases that already
     * ran that migration before the change.
     */
    public function up(): void
    {
        DB::table('subscription_plans')
            ->where('slug', 'basic')
            ->update(['trial_period_days' => 7]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')
            ->where('slug', 'basic')
            ->update(['trial_period_days' => 14]);
    }
};
