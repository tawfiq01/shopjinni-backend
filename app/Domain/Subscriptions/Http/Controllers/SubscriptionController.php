<?php

namespace App\Domain\Subscriptions\Http\Controllers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Domain\Subscriptions\Services\SubscriptionLifecycleService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionLifecycleService $lifecycle) {}

    /**
     * Bundles status, plan, and usage-vs-limit counts in one response so
     * the app needs a single call for the whole billing screen and the
     * dashboard status banner.
     */
    public function show(Request $request)
    {
        $companyId = $request->user()->company_id;
        $subscription = Subscription::with('plan')->where('company_id', $companyId)->firstOrFail();

        return response()->json($this->formatted($subscription, $companyId));
    }

    public function changePlan(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        $subscription = $this->lifecycle->changePlan($request->user()->company_id, $plan);

        return response()->json($this->formatted($subscription->load('plan'), $request->user()->company_id));
    }

    private function formatted(Subscription $subscription, int $companyId): array
    {
        $plan = $subscription->plan;

        return [
            'status' => $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'is_lifetime' => $subscription->is_lifetime,
            'payment_due_since' => $subscription->payment_due_since?->toIso8601String(),
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'monthly_price' => (float) $plan->monthly_price,
                'yearly_price' => (float) $plan->yearly_price,
                'max_users' => $plan->max_users,
                'max_products' => $plan->max_products,
                'max_branches' => $plan->max_branches,
                'features' => $plan->features ?? [],
            ],
            'usage' => [
                'users' => ['current' => User::where('company_id', $companyId)->count(), 'max' => $plan->max_users],
                'branches' => ['current' => Branch::count(), 'max' => $plan->max_branches],
                'products' => ['current' => ProductVariantColor::count(), 'max' => $plan->max_products],
            ],
        ];
    }
}
