<?php

namespace App\Domain\Subscriptions\Http\Controllers\Admin;

use App\Domain\Companies\Models\Company;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Domain\Subscriptions\Services\SubscriptionLifecycleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionLifecycleService $lifecycle) {}

    public function index()
    {
        return response()->json([
            'data' => Subscription::with(['company', 'plan'])->orderByDesc('id')->get(),
        ]);
    }

    /** Manual override — the only way "suspended" is ever set or cleared. */
    public function updateStatus(Request $request, Company $company)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['suspended', 'active'])],
        ]);

        $subscription = $data['status'] === 'suspended'
            ? $this->lifecycle->suspend($company->id)
            : $this->lifecycle->reactivate($company->id);

        return response()->json($subscription->load('plan'));
    }

    /** Assign any platform plan without changing the shop's current subscription status or dates. */
    public function updatePlan(Request $request, Company $company)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        $subscription = $this->lifecycle->assignPlan($company->id, $plan);

        return response()->json($subscription->load('plan'));
    }
}
