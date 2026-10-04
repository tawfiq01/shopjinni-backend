<?php

namespace Tests\Feature;

use App\Domain\Auth\Support\PermissionCatalog;
use App\Domain\Companies\Models\Company;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionPlan;
use App\Domain\Subscriptions\Services\SubscriptionLifecycleService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Only permissions, deliberately no Role — see the identical note on
     * assignCompanyRole()/RegistrationTest::seedPermissions(): a
     * company-less Role here would create a team_id-NULL row that
     * poisons every subsequent company's role-creation check in the same
     * test with a spurious RoleAlreadyExists.
     */
    private function seedPermissions(): void
    {
        foreach (PermissionCatalog::ALL as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    /** @return array{0: Company, 1: User} */
    private function actingAsAdminOnPlan(string $planSlug): array
    {
        $this->seedPermissions();

        $plan = SubscriptionPlan::where('slug', $planSlug)->firstOrFail();
        $company = Company::create(['name' => 'Test Company']);
        Subscription::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'is_lifetime' => true,
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', PermissionCatalog::ALL);
        $this->actingAs($user, 'sanctum');

        return [$company, $user];
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_registering_starts_a_trial_subscription_on_the_basic_plan(): void
    {
        $this->seedPermissions();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password123',
            'company_name' => 'Fresh Shop',
        ])->assertCreated();

        $token = $response->json('token');
        $subscription = $this->withToken($token)->getJson('/api/company/subscription')->assertOk();

        $subscription->assertJsonPath('status', 'trial')
            ->assertJsonPath('plan.slug', 'basic')
            ->assertJsonPath('is_lifetime', false);
        $this->assertNotNull($subscription->json('trial_ends_at'));
    }

    public function test_a_company_without_a_subscription_row_is_blocked_from_business_routes(): void
    {
        $this->seedPermissions();

        // Deliberately NOT using $this->createCompany() — simulates a
        // company that somehow has no Subscription row, proving
        // EnsureSubscriptionActive fails closed rather than silently
        // allowing access.
        $company = Company::create(['name' => 'No Subscription Shop']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', PermissionCatalog::ALL);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/catalog/brands')->assertForbidden();
    }

    public function test_admin_can_view_and_change_their_subscription_plan(): void
    {
        [, $admin] = $this->actingAsAdminOnPlan('basic');
        $premium = SubscriptionPlan::where('slug', 'premium')->firstOrFail();

        $response = $this->putJson('/api/company/subscription/plan', ['plan_id' => $premium->id])->assertOk();

        $response->assertJsonPath('plan.slug', 'premium')->assertJsonPath('status', 'payment_due');
        $this->assertSame('payment_due', Subscription::where('company_id', $admin->company_id)->value('status'));
    }

    public function test_non_admin_cannot_change_subscription_plan(): void
    {
        $this->seedPermissions();

        $company = $this->createCompany();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Salesperson', ['pos.sell']);
        $this->actingAs($user, 'sanctum');

        $plan = SubscriptionPlan::where('slug', 'premium')->firstOrFail();
        $this->putJson('/api/company/subscription/plan', ['plan_id' => $plan->id])->assertForbidden();
    }

    public function test_exceeding_the_max_branches_limit_is_rejected(): void
    {
        [$company] = $this->actingAsAdminOnPlan('basic'); // max_branches = 1, and provisioning didn't run so 0 exist yet

        $this->postJson('/api/branches', ['name' => 'Second Branch'])->assertCreated();
        $response = $this->postJson('/api/branches', ['name' => 'Third Branch'])->assertUnprocessable();

        $response->assertJsonValidationErrors('plan_limit');
        $this->assertSame(1, $company->branches()->count());
    }

    public function test_exceeding_the_max_users_limit_is_rejected(): void
    {
        $this->actingAsAdminOnPlan('basic'); // max_users = 3 (the acting admin themselves counts as 1)

        $this->postJson('/api/users', [
            'name' => 'Staff Two', 'email' => 'staff2@example.com', 'password' => 'password123', 'role' => 'Admin',
        ])->assertCreated();

        $this->postJson('/api/users', [
            'name' => 'Staff Three', 'email' => 'staff3@example.com', 'password' => 'password123', 'role' => 'Admin',
        ])->assertCreated();

        $this->postJson('/api/users', [
            'name' => 'Staff Four', 'email' => 'staff4@example.com', 'password' => 'password123', 'role' => 'Admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('plan_limit');
    }

    public function test_advanced_reports_require_the_feature_flag(): void
    {
        $this->actingAsAdminOnPlan('basic'); // features: []
        $this->getJson('/api/reports/purchases/summary')->assertForbidden();
    }

    public function test_premium_plan_can_access_advanced_reports(): void
    {
        $this->actingAsAdminOnPlan('premium'); // features: ['advanced_reports']
        $this->getJson('/api/reports/purchases/summary')->assertOk();
    }

    public function test_a_blocked_subscription_status_blocks_business_routes_but_not_billing_routes(): void
    {
        [$company, $admin] = $this->actingAsAdminOnPlan('premium');
        Subscription::where('company_id', $company->id)->update(['status' => Subscription::STATUS_EXPIRED, 'is_lifetime' => false]);

        $this->getJson('/api/catalog/brands')->assertForbidden();
        $this->getJson('/api/company')->assertOk();
        $this->getJson('/api/company/subscription')->assertOk()->assertJsonPath('status', 'expired');
        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_a_deactivated_company_blocks_login_for_its_users(): void
    {
        $company = $this->createCompany();
        $company->update(['is_active' => false]);
        User::factory()->create(['company_id' => $company->id, 'password' => bcrypt('password123'), 'email' => 'blocked@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'blocked@example.com', 'password' => 'password123'])
            ->assertUnprocessable();
    }

    public function test_a_company_less_super_admin_can_still_log_in(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => true, 'company_id' => null, 'email' => 'super@example.com', 'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'super@example.com', 'password' => 'password123'])->assertOk();
    }

    public function test_super_admin_can_list_and_activate_deactivate_any_company(): void
    {
        $this->actingAsSuperAdmin();
        [$company] = [$this->createCompany('Some Shop')];

        $this->getJson('/api/admin/companies')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/admin/companies/{$company->id}/deactivate")->assertOk()->assertJsonPath('is_active', false);
        $this->postJson("/api/admin/companies/{$company->id}/activate")->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_super_admin_can_assign_any_plan_without_changing_subscription_status_or_term(): void
    {
        $this->actingAsSuperAdmin();
        $company = $this->createCompany('Package Change Shop');
        $subscription = Subscription::where('company_id', $company->id)->firstOrFail();
        $subscription->update([
            'status' => Subscription::STATUS_SUSPENDED,
            'is_lifetime' => false,
            'current_period_ends_at' => now()->addDays(12),
        ]);
        $periodEnd = $subscription->fresh()->current_period_ends_at->toIso8601String();
        $inactivePlan = SubscriptionPlan::where('slug', 'premium')->firstOrFail();
        $inactivePlan->update(['is_active' => false]);

        $this->putJson("/api/admin/subscriptions/{$company->id}/plan", ['plan_id' => $inactivePlan->id])
            ->assertOk()
            ->assertJsonPath('plan_id', $inactivePlan->id);

        $subscription->refresh();
        $this->assertSame($inactivePlan->id, $subscription->plan_id);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $subscription->status);
        $this->assertSame($periodEnd, $subscription->current_period_ends_at->toIso8601String());
    }

    public function test_non_super_admin_cannot_assign_a_shop_plan(): void
    {
        [$company] = $this->actingAsAdminOnPlan('basic');
        $premium = SubscriptionPlan::where('slug', 'premium')->firstOrFail();

        $this->putJson("/api/admin/subscriptions/{$company->id}/plan", ['plan_id' => $premium->id])
            ->assertForbidden();
    }

    public function test_a_non_super_admin_cannot_access_the_admin_panel(): void
    {
        $this->actingAsAdminOnPlan('basic');
        $this->getJson('/api/admin/companies')->assertForbidden();
    }

    public function test_super_admin_recording_a_payment_reactivates_the_subscription(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();
        $company = $this->createCompany();
        Subscription::where('company_id', $company->id)->update(['status' => Subscription::STATUS_PAYMENT_DUE, 'is_lifetime' => false]);

        $response = $this->postJson('/api/admin/payments', [
            'company_id' => $company->id,
            'method' => 'bkash',
            'billing_cycle' => 'monthly',
            'reference' => 'TXN123',
        ])->assertCreated();

        $this->assertSame('active', Subscription::where('company_id', $company->id)->value('status'));
        $this->assertSame($superAdmin->id, $response->json('recorded_by.id'));
    }

    public function test_lifecycle_service_advances_a_trial_past_its_end_date_to_payment_due(): void
    {
        $company = Company::create(['name' => 'Lifecycle Shop']);
        $plan = SubscriptionPlan::where('slug', 'basic')->firstOrFail();
        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIAL,
            'billing_cycle' => 'monthly',
            'trial_ends_at' => now()->subDay(),
        ]);

        app(SubscriptionLifecycleService::class)->tick($subscription, CarbonImmutable::now());

        $this->assertSame('payment_due', $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->payment_due_since);
    }

    public function test_lifecycle_service_never_advances_a_suspended_or_lifetime_subscription(): void
    {
        $lifecycle = app(SubscriptionLifecycleService::class);
        $plan = SubscriptionPlan::where('slug', 'basic')->firstOrFail();

        $suspendedCompany = Company::create(['name' => 'Suspended Shop']);
        $suspended = Subscription::create([
            'company_id' => $suspendedCompany->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_SUSPENDED, 'billing_cycle' => 'monthly',
            'trial_ends_at' => now()->subYear(),
        ]);

        $lifetimeCompany = Company::create(['name' => 'Lifetime Shop']);
        $lifetime = Subscription::create([
            'company_id' => $lifetimeCompany->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE, 'billing_cycle' => 'monthly',
            'is_lifetime' => true, 'current_period_ends_at' => now()->subYear(),
        ]);

        $lifecycle->tick($suspended, CarbonImmutable::now());
        $lifecycle->tick($lifetime, CarbonImmutable::now());

        $this->assertSame('suspended', $suspended->fresh()->status);
        $this->assertSame('active', $lifetime->fresh()->status);
    }
}
