<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Companies\Models\Company;
use App\Domain\Subscriptions\Models\PaymentRecord;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * All status-transition and renewal logic in one place, shared by the
 * daily CheckSubscriptionStatuses command and the Super Admin payment/plan
 * endpoints — a status change should never happen ad hoc in a controller.
 */
class SubscriptionLifecycleService
{
    public function startTrial(Company $company, SubscriptionPlan $plan): Subscription
    {
        return Subscription::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIAL,
            'billing_cycle' => 'monthly',
            'trial_ends_at' => now()->addDays($plan->trial_period_days),
        ]);
    }

    /**
     * Shop-owner-initiated plan change. No proration — there's no payment
     * gateway to prorate against (offline billing model), so this simply
     * asks for a fresh payment to activate the new plan.
     */
    public function changePlan(int $companyId, SubscriptionPlan $newPlan): Subscription
    {
        return DB::transaction(function () use ($companyId, $newPlan) {
            $subscription = Subscription::where('company_id', $companyId)->lockForUpdate()->firstOrFail();

            $subscription->update([
                'plan_id' => $newPlan->id,
                'status' => Subscription::STATUS_PAYMENT_DUE,
                'payment_due_since' => now(),
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * Records a payment and reactivates the subscription in one step —
     * extends from now(), unless the current period hasn't lapsed yet
     * (an early renewal), in which case it extends from that existing
     * end date instead so nothing already paid for is lost.
     */
    public function recordPayment(
        int $companyId,
        string $method,
        ?string $reference,
        string $billingCycle,
        User $recordedBy,
        ?string $notes,
    ): PaymentRecord {
        return DB::transaction(function () use ($companyId, $method, $reference, $billingCycle, $recordedBy, $notes) {
            $subscription = Subscription::where('company_id', $companyId)->lockForUpdate()->firstOrFail();
            $plan = SubscriptionPlan::findOrFail($subscription->plan_id);

            $periodStart = now();
            $currentEnd = $subscription->current_period_ends_at;
            if ($currentEnd !== null && $currentEnd->isFuture()) {
                $periodStart = $currentEnd;
            }
            $periodEnd = $billingCycle === 'yearly' ? $periodStart->copy()->addYear() : $periodStart->copy()->addMonth();
            $amount = $billingCycle === 'yearly' ? $plan->yearly_price : $plan->monthly_price;

            $subscription->update([
                'billing_cycle' => $billingCycle,
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_ends_at' => $periodEnd,
                'payment_due_since' => null,
            ]);

            return PaymentRecord::create([
                'company_id' => $companyId,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'amount' => $amount,
                'billing_cycle' => $billingCycle,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => now(),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'recorded_by' => $recordedBy->id,
                'notes' => $notes,
            ]);
        });
    }

    public function suspend(int $companyId): Subscription
    {
        return DB::transaction(function () use ($companyId) {
            $subscription = Subscription::where('company_id', $companyId)->lockForUpdate()->firstOrFail();
            $subscription->update(['status' => Subscription::STATUS_SUSPENDED]);

            return $subscription->fresh();
        });
    }

    public function reactivate(int $companyId): Subscription
    {
        return DB::transaction(function () use ($companyId) {
            $subscription = Subscription::where('company_id', $companyId)->lockForUpdate()->firstOrFail();
            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'payment_due_since' => null,
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * One clock-driven transition step for a single subscription. Never
     * touches "suspended" in either direction — that's Super Admin-only.
     * is_lifetime subscriptions are never advanced.
     */
    public function tick(Subscription $subscription, CarbonImmutable $now): void
    {
        if ($subscription->is_lifetime || $subscription->status === Subscription::STATUS_SUSPENDED) {
            return;
        }

        if ($subscription->status === Subscription::STATUS_TRIAL
            && $subscription->trial_ends_at !== null
            && $now->isAfter($subscription->trial_ends_at)) {
            $subscription->update(['status' => Subscription::STATUS_PAYMENT_DUE, 'payment_due_since' => $now]);

            return;
        }

        if ($subscription->status === Subscription::STATUS_ACTIVE
            && $subscription->current_period_ends_at !== null
            && $now->isAfter($subscription->current_period_ends_at)) {
            $subscription->update(['status' => Subscription::STATUS_PAYMENT_DUE, 'payment_due_since' => $now]);

            return;
        }

        if ($subscription->status === Subscription::STATUS_PAYMENT_DUE
            && $subscription->payment_due_since !== null
            && $now->isAfter($subscription->payment_due_since->addDays(config('subscription.payment_due_days')))) {
            $subscription->update(['status' => Subscription::STATUS_GRACE]);

            return;
        }

        if ($subscription->status === Subscription::STATUS_GRACE
            && $subscription->payment_due_since !== null
            && $now->isAfter($subscription->payment_due_since->addDays(
                config('subscription.payment_due_days') + config('subscription.grace_period_days'),
            ))) {
            $subscription->update(['status' => Subscription::STATUS_EXPIRED]);
        }
    }
}
