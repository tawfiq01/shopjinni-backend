<?php

namespace App\Domain\Companies\Services;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Auth\Support\PermissionCatalog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Support\CurrentCompany;
use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Domain\Subscriptions\Services\SubscriptionLifecycleService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class CompanyProvisioningService
{
    public function __construct(private readonly SubscriptionLifecycleService $subscriptions) {}

    /**
     * The default Chart of Accounts every new company starts with.
     * Shared with ChartOfAccountSeeder so a fresh install and a live
     * self-service signup provision identical books.
     */
    public const DEFAULT_ACCOUNTS = [
        ['code' => '1000', 'name' => 'Cash in Hand', 'type' => 'asset'],
        ['code' => '1010', 'name' => 'Bank', 'type' => 'asset'],
        ['code' => '1020', 'name' => 'bKash', 'type' => 'asset'],
        ['code' => '1030', 'name' => 'Nagad', 'type' => 'asset'],
        ['code' => '1040', 'name' => 'Card / Other MFS', 'type' => 'asset'],
        ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset'],
        ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset'],
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability'],
        ['code' => '2100', 'name' => 'VAT / Tax Payable', 'type' => 'liability'],
        ['code' => '3000', 'name' => "Owner's Equity", 'type' => 'equity'],
        ['code' => '3100', 'name' => 'Opening Balance Equity', 'type' => 'equity'],
        ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'income'],
        ['code' => '4100', 'name' => 'Other Income', 'type' => 'income'],
        ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense'],
        ['code' => '5100', 'name' => 'Operating Expenses', 'type' => 'expense'],
    ];

    /**
     * Default payment methods, each wired to its matching account above.
     * Shared with PaymentMethodSeeder for the same reason.
     */
    public const DEFAULT_PAYMENT_METHODS = [
        ['name' => 'Cash', 'account_code' => '1000'],
        ['name' => 'Bank', 'account_code' => '1010'],
        ['name' => 'bKash', 'account_code' => '1020'],
        ['name' => 'Nagad', 'account_code' => '1030'],
        ['name' => 'Card / Other MFS', 'account_code' => '1040'],
    ];

    /**
     * Creates a brand-new, fully-usable company: a main branch, a full
     * Chart of Accounts, default payment methods, and — when an owner is
     * given — attaches them to it as Admin.
     *
     * Runs entirely inside CurrentCompany::forceFor(), because none of
     * this happens under an authenticated request for the *new* company
     * yet (registration: the user doesn't have a token for it until this
     * finishes; onboarding: they're authenticated, but as a user whose
     * company_id is still null; seeding: no HTTP user at all) — every
     * tenant-scoped model's global scope would otherwise fail closed.
     */
    public function provision(string $name, ?string $address, ?string $phone, ?User $owner = null): Company
    {
        return DB::transaction(function () use ($name, $address, $phone, $owner) {
            $company = Company::create([
                'name' => $name,
                'address' => $address,
                'phone' => $phone,
            ]);

            // Not wrapped in forceFor — Subscription is deliberately
            // unscoped (see its model docblock), so company_id is just
            // passed explicitly like $owner->company_id below.
            $this->subscriptions->startTrial($company, SubscriptionPlan::where('slug', 'basic')->firstOrFail());

            CurrentCompany::forceFor($company->id, function () use ($company, $owner) {
                // company_id is deliberately not mass-assignable on any
                // tenant model (BelongsToCompany auto-fills it instead) —
                // wrapping every create() in forceFor() is what makes that
                // auto-fill resolve to the right company here.
                Branch::create([
                    'name' => 'Main Branch',
                    'is_main' => true,
                    'is_active' => true,
                ]);

                foreach (self::DEFAULT_ACCOUNTS as $account) {
                    ChartOfAccount::create([
                        ...$account,
                        'is_system' => true,
                        'is_active' => true,
                    ]);
                }

                foreach (self::DEFAULT_PAYMENT_METHODS as $method) {
                    $account = ChartOfAccount::where('code', $method['account_code'])->first();
                    if (! $account) {
                        continue;
                    }

                    PaymentMethod::create([
                        'name' => $method['name'],
                        'chart_of_account_id' => $account->id,
                        'is_active' => true,
                    ]);
                }

                // Roles are per-company (spatie "teams" = company, via
                // CompanyTeamResolver) — Role::create() auto-injects
                // team_id from the resolver here because we're inside
                // forceFor(), same reason Branch/ChartOfAccount above
                // don't need company_id passed explicitly either.
                foreach (PermissionCatalog::DEFAULT_ROLE_PERMISSIONS as $roleName => $permissions) {
                    $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
                    if ($roleName === 'Admin') {
                        $role->is_system = true;
                        $role->save();
                    }
                    $role->syncPermissions($permissions);
                }

                if ($owner) {
                    $owner->company_id = $company->id;
                    $owner->save();
                    // findByName() (unlike a raw where()) does spatie's
                    // team-scoped lookup, so — still inside forceFor() —
                    // this resolves to THIS company's own Admin role, not
                    // some other company's.
                    $owner->assignRole(Role::findByName('Admin', 'web'));
                }
            });

            return $company;
        });
    }
}
