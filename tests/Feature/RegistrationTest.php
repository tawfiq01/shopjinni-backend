<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Auth\Support\PermissionCatalog;
use App\Domain\Branches\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Only permissions, deliberately no Role here — every endpoint under
     * test provisions its OWN company (CompanyProvisioningService creates
     * that company's Admin/Salesperson/Accountant roles itself now).
     * Seeding a bare, company-less "Admin" role here would create a
     * team_id-NULL row that spatie's own team-scoped lookup treats as a
     * wildcard match for every subsequent company's role-creation check
     * in the same test, causing a RoleAlreadyExists 500 the moment
     * provisioning runs.
     */
    private function seedPermissions(): void
    {
        foreach (PermissionCatalog::ALL as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    public function test_registering_provisions_an_isolated_company_and_logs_the_owner_in(): void
    {
        $this->seedPermissions();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Karim Uddin',
            'email' => 'karim@example.com',
            'password' => 'password123',
            'company_name' => 'Karim Mobile Shop',
            'company_address' => 'Gulshan, Dhaka',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('Karim Mobile Shop', $response->json('user.company_name'));
        $this->assertContains('Admin', $response->json('user.roles'));

        $user = User::where('email', 'karim@example.com')->firstOrFail();
        $this->assertNotNull($user->company_id);

        $this->actingAs($user, 'sanctum');
        $this->assertSame('Main Branch', Branch::where('is_main', true)->value('name'));
        $this->assertCount(15, ChartOfAccount::all());
        $this->assertCount(5, PaymentMethod::all());
    }

    public function test_registering_a_second_company_does_not_see_the_first_companys_data(): void
    {
        $this->seedPermissions();

        $this->postJson('/api/auth/register', [
            'name' => 'Owner One', 'email' => 'one@example.com', 'password' => 'password123',
            'company_name' => 'Shop One',
        ])->assertCreated();

        $tokenTwo = $this->postJson('/api/auth/register', [
            'name' => 'Owner Two', 'email' => 'two@example.com', 'password' => 'password123',
            'company_name' => 'Shop Two',
        ])->assertCreated()->json('token');

        $response = $this->withToken($tokenTwo)->postJson('/api/catalog/brands', ['name' => 'Samsung']);
        $response->assertCreated();

        $list = $this->withToken($tokenTwo)->getJson('/api/catalog/brands')->assertOk();
        $this->assertCount(1, $list->json('data'));
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $this->seedPermissions();

        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Someone', 'email' => 'taken@example.com', 'password' => 'password123',
            'company_name' => 'New Shop',
        ])->assertUnprocessable();
    }

    public function test_google_sign_in_creates_a_new_user_with_no_company_and_redirects_with_a_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'newgoogleuser@example.com',
                'name' => 'Google User',
            ], 200),
        ]);

        $response = $this->get('/api/auth/google/callback?code=fake-code');

        $response->assertRedirect();
        $this->assertStringContainsString('#/auth/callback?token=', $response->headers->get('Location'));

        $user = User::where('email', 'newgoogleuser@example.com')->firstOrFail();
        $this->assertNull($user->company_id);
    }

    public function test_google_sign_in_logs_in_an_existing_user_without_touching_their_company(): void
    {
        $this->seedPermissions();
        $this->postJson('/api/auth/register', [
            'name' => 'Existing', 'email' => 'existing@example.com', 'password' => 'password123',
            'company_name' => 'Existing Shop',
        ])->assertCreated();

        $companyIdBefore = User::where('email', 'existing@example.com')->value('company_id');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'existing@example.com'], 200),
        ]);

        $this->get('/api/auth/google/callback?code=fake-code')->assertRedirect();

        $this->assertSame(1, User::where('email', 'existing@example.com')->count());
        $this->assertSame($companyIdBefore, User::where('email', 'existing@example.com')->value('company_id'));
    }

    public function test_onboarding_completes_signup_for_a_company_less_user_and_rejects_a_repeat(): void
    {
        $this->seedPermissions();

        $user = User::factory()->create(['company_id' => null]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/auth/onboarding/complete', [
            'company_name' => 'New Onboarded Shop',
        ])->assertOk()->assertJsonPath('user.company_name', 'New Onboarded Shop');

        $this->assertNotNull($user->fresh()->company_id);

        $this->postJson('/api/auth/onboarding/complete', [
            'company_name' => 'Second Company Attempt',
        ])->assertStatus(409);
    }
}
