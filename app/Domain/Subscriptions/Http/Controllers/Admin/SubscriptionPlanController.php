<?php

namespace App\Domain\Subscriptions\Http\Controllers\Admin;

use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionPlanController extends Controller
{
    public function index()
    {
        return response()->json(['data' => SubscriptionPlan::orderBy('sort_order')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        return response()->json(SubscriptionPlan::create($data), 201);
    }

    public function update(Request $request, SubscriptionPlan $plan)
    {
        $data = $request->validate($this->rules($plan->id));

        $plan->update($data);

        return response()->json($plan->fresh());
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('subscription_plans', 'slug')->ignore($ignoreId)],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'yearly_price' => ['required', 'numeric', 'min:0'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_products' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'trial_period_days' => ['required', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
