<?php

namespace App\Domain\Companies\Http\Controllers\Admin;

use App\Domain\Companies\Models\Company;
use App\Http\Controllers\Controller;

/**
 * Super Admin only (see the 'superadmin' route group in routes/api.php) —
 * cross-tenant by design, reading every company regardless of the acting
 * user's own company_id. Company itself was never BelongsToCompany-scoped
 * (it's the tenant root, not tenant data), so no scope-bypassing needed.
 */
class CompanyAdminController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Company::with('subscription.plan')
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Company $company) => $this->summarize($company)),
        ]);
    }

    public function show(Company $company)
    {
        $company->load('subscription.plan')->loadCount('users');

        return response()->json($this->summarize($company));
    }

    public function activate(Company $company)
    {
        $company->update(['is_active' => true]);

        return response()->json($this->summarize($company->fresh(['subscription.plan'])->loadCount('users')));
    }

    public function deactivate(Company $company)
    {
        $company->update(['is_active' => false]);

        return response()->json($this->summarize($company->fresh(['subscription.plan'])->loadCount('users')));
    }

    private function summarize(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'address' => $company->address,
            'phone' => $company->phone,
            'is_active' => $company->is_active,
            'user_count' => $company->users_count,
            'subscription_status' => $company->subscription?->status,
            'plan_id' => $company->subscription?->plan_id,
            'plan_name' => $company->subscription?->plan?->name,
            'created_at' => $company->created_at?->toIso8601String(),
        ];
    }
}
