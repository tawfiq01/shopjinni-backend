<?php

namespace App\Domain\Subscriptions\Http\Controllers;

use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;

class SubscriptionPlanController extends Controller
{
    /** Public plan list for the "choose/change plan" screen. */
    public function index()
    {
        return response()->json([
            'data' => SubscriptionPlan::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug', 'monthly_price', 'yearly_price', 'max_users', 'max_products', 'max_branches', 'features']),
        ]);
    }
}
