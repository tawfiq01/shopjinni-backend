<?php

namespace Tests;

use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Support\CurrentCompany;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test company needs a Subscription row, or EnsureSubscriptionActive
     * fails closed and 403s every business route — use this instead of
     * Company::create() directly in every test's actingAsX() helper.
     * Lifetime/active so no test has to think about plan limits or
     * lifecycle status unless it's specifically testing those.
     */
    protected function createCompany(string $name = 'Test Company'): Company
    {
        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'premium'],
            [
                'name' => 'Premium',
                'monthly_price' => 2000,
                'yearly_price' => 20000,
                'trial_period_days' => 14,
                'features' => ['advanced_reports'],
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        $company = Company::create(['name' => $name]);

        Subscription::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'yearly',
            'is_lifetime' => true,
        ]);

        return $company;
    }

    /**
     * Roles are per-company now (spatie "teams" = company — see
     * CompanyTeamResolver). Three separate footguns this closes, all
     * found the hard way while retrofitting the existing suite:
     *
     * 1. A bare `Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'])`
     *    called with no company context (e.g. before actingAs()) creates
     *    a "global" role with team_id NULL — and spatie's own team-scoped
     *    lookup (`whereNull(team_id)->orWhere(team_id, current)`) then
     *    treats that null-team row as a wildcard match for EVERY future
     *    company's "does a role by this name already exist" check in that
     *    same test, throwing RoleAlreadyExists the moment any endpoint
     *    that provisions a company (registration, onboarding) runs later
     *    in the same test.
     * 2. Even when the ROLE is created inside forceFor(), a separate
     *    `$user->assignRole($role)` call made afterwards — outside that
     *    same forceFor() — resolves team_id as null again for the pivot
     *    insert (model_has_roles.team_id is NOT NULL), so creation and
     *    assignment have to happen in the SAME forceFor() callback.
     * 3. Plain Eloquent `Role::firstOrCreate([...])` (as opposed to
     *    spatie's own `Role::create()`) does its FIND half with a bare,
     *    completely unscoped `where(['name' => ..., 'guard_name' => ...])`
     *    — spatie only made `create()` team-aware, not the inherited
     *    `firstOrCreate()`. In a test with two companies that both get an
     *    "Admin" role, the second call would silently find and return the
     *    FIRST company's Admin row instead of creating its own. Always
     *    look up the existing row with an explicit team_id filter first.
     *
     * Never call Role::firstOrCreate()/Role::create() directly in a test
     * — always go through these two helpers.
     */
    protected function assignCompanyRole(Company $company, User $user, string $name, array $permissions = []): Role
    {
        return CurrentCompany::forceFor($company->id, function () use ($company, $name, $permissions, $user) {
            $role = $this->findOrCreateCompanyRole($company, $name, $permissions);
            $user->assignRole($role);

            return $role;
        });
    }

    /**
     * For a role that just needs to EXIST for a company (e.g. so an API
     * request under test can validate `role: 'Salesperson'` against it),
     * without assigning it to anyone in the test fixture itself.
     */
    protected function createCompanyRole(Company $company, string $name, array $permissions = []): Role
    {
        return CurrentCompany::forceFor(
            $company->id,
            fn () => $this->findOrCreateCompanyRole($company, $name, $permissions),
        );
    }

    private function findOrCreateCompanyRole(Company $company, string $name, array $permissions): Role
    {
        $role = Role::where('team_id', $company->id)->where('name', $name)->where('guard_name', 'web')->first();

        if (! $role) {
            $role = Role::create(['name' => $name, 'guard_name' => 'web']);
            if ($name === 'Admin') {
                // Mirrors CompanyProvisioningService::provision() — tests
                // that exercise the is_system delete-guard need this set
                // the same way real provisioning sets it.
                $role->is_system = true;
                $role->save();
            }
        }

        if ($permissions) {
            $role->syncPermissions($permissions);
        }

        return $role;
    }
}
