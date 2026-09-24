<?php

namespace App\Domain\Subscriptions\Support;

use App\Domain\Companies\Support\CurrentCompany;
use App\Domain\Subscriptions\Models\Subscription;

/**
 * Checks whether the current company's active plan includes a given
 * feature flag (spec §8 — e.g. Basic lacks 'advanced_reports', Premium
 * has it). Reads CurrentCompany rather than taking a company id, so it
 * can be called the same way permission checks are — from request-handling
 * code, not console/provisioning contexts.
 */
class SubscriptionFeatureGate
{
    public static function has(string $key): bool
    {
        $companyId = CurrentCompany::id();
        if ($companyId === null) {
            return false;
        }

        $subscription = Subscription::where('company_id', $companyId)->with('plan')->first();

        return $subscription?->plan?->hasFeature($key) ?? false;
    }
}
