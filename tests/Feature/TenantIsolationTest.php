<?php

namespace Tests\Feature;

use App\Domain\Companies\Models\Company;
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The safety net for the whole multi-tenancy feature: proves Company A's
 * Admin can never read, list, or mutate Company B's data, across a
 * representative sample of modules. Every one of these would have failed
 * before BelongsToCompany existed — if one of them ever fails again in the
 * future, that's a real cross-tenant leak, not a flaky test.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyAdmin(string $companyName): User
    {
        $permissions = [
            'catalog.manage', 'customers.manage', 'distributors.manage',
            'accounting.manage', 'accounting.view', 'reports.view', 'reports.view-cost',
            'users.manage', 'branches.manage', 'purchases.manage',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $company = $this->createCompany($companyName);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', $permissions);

        return $user;
    }

    public function test_catalog_brands_are_isolated_and_the_same_name_can_be_reused_per_company(): void
    {
        $userA = $this->makeCompanyAdmin('Company A');
        $this->actingAs($userA, 'sanctum');
        $this->postJson('/api/catalog/brands', ['name' => 'Samsung'])->assertCreated();

        $userB = $this->makeCompanyAdmin('Company B');
        $this->actingAs($userB, 'sanctum');

        $list = $this->getJson('/api/catalog/brands')->assertOk();
        $this->assertCount(0, $list->json('data'));

        // Proves the unique index is per-company, not global.
        $this->postJson('/api/catalog/brands', ['name' => 'Samsung'])->assertCreated();
    }

    public function test_a_branch_cannot_be_fetched_or_updated_across_companies(): void
    {
        $userA = $this->makeCompanyAdmin('Company A');
        $this->actingAs($userA, 'sanctum');
        $branchA = $this->postJson('/api/branches', ['name' => 'Dhanmondi Branch'])->assertCreated()->json('data');

        $userB = $this->makeCompanyAdmin('Company B');
        $this->actingAs($userB, 'sanctum');

        $this->putJson("/api/branches/{$branchA['id']}", ['name' => 'Hijacked'])->assertNotFound();

        // The read-only list endpoint is intentionally open to any
        // authenticated user (POS/reporting screens need it) — proves it
        // still only ever shows the caller's own company's branches.
        $list = $this->getJson('/api/branches')->assertOk()->json('data');
        $this->assertCount(0, $list);
    }

    public function test_customers_and_distributors_are_isolated(): void
    {
        $userA = $this->makeCompanyAdmin('Company A');
        $this->actingAs($userA, 'sanctum');
        $customerA = $this->postJson('/api/customers', ['name' => 'Rahim', 'mobile' => '01700000000'])
            ->assertCreated()->json('data');
        $distributorA = Distributor::factory()->create(['name' => 'ABC Distributors']);

        $userB = $this->makeCompanyAdmin('Company B');
        $this->actingAs($userB, 'sanctum');

        $this->getJson('/api/customers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/customers/{$customerA['id']}")->assertNotFound();

        $this->getJson('/api/distributors')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/distributors/{$distributorA->id}")->assertNotFound();
    }

    public function test_accounting_ledger_and_chart_of_accounts_are_isolated(): void
    {
        $userA = $this->makeCompanyAdmin('Company A');
        $this->actingAs($userA, 'sanctum');
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $accountsA = $this->getJson('/api/accounting/accounts')->assertOk()->json('data');
        $this->assertNotEmpty($accountsA);

        $userB = $this->makeCompanyAdmin('Company B');
        $this->actingAs($userB, 'sanctum');

        // Company B has never been seeded — its chart of accounts is
        // genuinely empty, and reusing the exact same account code
        // Company A already used (e.g. "1000") does not collide.
        $this->getJson('/api/accounting/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/accounting/accounts', [
            'code' => $accountsA[0]['code'],
            'name' => 'My Own Cash Account',
            'type' => 'asset',
        ])->assertCreated();
    }

    public function test_stock_report_is_isolated(): void
    {
        $userA = $this->makeCompanyAdmin('Company A');
        $this->actingAs($userA, 'sanctum');
        $this->postJson('/api/catalog/brands', ['name' => 'Xiaomi'])->assertCreated();

        $userB = $this->makeCompanyAdmin('Company B');
        $this->actingAs($userB, 'sanctum');

        $report = $this->getJson('/api/reports/stock')->assertOk();
        $this->assertCount(0, $report->json('data'));
    }

    public function test_users_list_and_user_mutation_are_isolated(): void
    {
        // User has no BelongsToCompany scope by design (Sanctum resolves
        // it before a company is known) — UserController has to filter
        // and guard explicitly instead, which is exactly what this test
        // is proving actually happens.
        $userA = $this->makeCompanyAdmin('Company A');
        $this->actingAs($userA, 'sanctum');

        $userB = $this->makeCompanyAdmin('Company B');
        $this->actingAs($userB, 'sanctum');

        $list = $this->getJson('/api/users')->assertOk()->json('data');
        $this->assertCount(1, $list); // only userB itself, not userA
        $this->assertSame($userB->id, $list[0]['id']);

        $this->putJson("/api/users/{$userA->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->postJson("/api/users/{$userA->id}/reset-password", ['password' => 'newpassword123'])->assertNotFound();
    }

    /**
     * Guards against the failure mode that matters most here: a future
     * domain model shipping without BelongsToCompany. Since every
     * company's Admin has full permissions within their own scope, one
     * missed model is a full cross-tenant leak the very first time it's
     * queried — this makes that an immediate, obvious test failure
     * instead of a silent gap someone finds in production.
     */
    public function test_every_domain_model_uses_belongs_to_company_except_the_documented_exceptions(): void
    {
        // Company is the tenant root — nothing to scope it to. User is
        // documented separately (see its own docblock): Sanctum resolves
        // it before Auth::user() exists, so a global scope on it would
        // break authentication for everyone. SubscriptionPlan is
        // platform-level, not tenant data. Subscription and PaymentRecord
        // are documented the same way as User (see their own docblocks):
        // written almost exclusively by a Super Admin acting on someone
        // else's company, so they use explicit company_id instead of the
        // trait.
        $exceptions = [
            \App\Domain\Companies\Models\Company::class,
            \App\Domain\Subscriptions\Models\SubscriptionPlan::class,
            \App\Domain\Subscriptions\Models\Subscription::class,
            \App\Domain\Subscriptions\Models\PaymentRecord::class,
        ];

        $unscoped = [];

        foreach (File::allFiles(app_path('Domain')) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class) || in_array($class, $exceptions, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            if (! in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                $unscoped[] = $class;
            }
        }

        $this->assertEmpty($unscoped, 'These models are missing BelongsToCompany: '.implode(', ', $unscoped));
    }
}
