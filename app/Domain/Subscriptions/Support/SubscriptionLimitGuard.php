<?php

namespace App\Domain\Subscriptions\Support;

use App\Domain\Subscriptions\Models\Subscription;
use Illuminate\Validation\ValidationException;

/**
 * Call from inside a DB::transaction(), immediately before the create()
 * it's guarding — lockForUpdate() here is what serializes two concurrent
 * requests for the same company against the same limit (e.g. two staff
 * invites landing at once on a plan with one seat left).
 */
class SubscriptionLimitGuard
{
    public static function ensure(int $companyId, string $limitColumn, int $currentCount, string $resourceLabel): void
    {
        $plan = Subscription::where('company_id', $companyId)->lockForUpdate()->first()?->plan;

        if ($plan && ! $plan->withinLimit($limitColumn, $currentCount)) {
            throw ValidationException::withMessages([
                'plan_limit' => ["Your plan allows up to {$plan->{$limitColumn}} {$resourceLabel}. Upgrade your plan to add more."],
            ]);
        }
    }
}
