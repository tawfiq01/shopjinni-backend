<?php

namespace App\Domain\Subscriptions\Http\Controllers\Admin;

use App\Domain\Companies\Models\Company;
use App\Domain\Subscriptions\Models\PaymentRecord;
use App\Domain\Subscriptions\Models\Subscription;
use App\Http\Controllers\Controller;

class SystemReportController extends Controller
{
    public function summary()
    {
        return response()->json([
            'total_companies' => Company::count(),
            'active_companies' => Company::where('is_active', true)->count(),
            'by_status' => Subscription::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'payments_this_month' => [
                'count' => PaymentRecord::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->count(),
                'total' => (float) PaymentRecord::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount'),
            ],
        ]);
    }
}
