<?php

namespace Tests\Feature;

use App\Domain\Branches\Models\Branch;
use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'users.manage', 'guard_name' => 'web']);

        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['users.manage']);
        // Exist (selectable by the /api/users 'role' field under test)
        // without being assigned to anyone yet.
        $this->createCompanyRole($company, 'Salesperson');
        $this->createCompanyRole($company, 'Accountant');
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_admin_can_create_a_salesperson_account(): void
    {
        $admin = $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/users', [
            'name' => 'Karim Cashier',
            'email' => 'karim@mobishop.test',
            'phone' => '01711111111',
            'branch_id' => $branch->id,
            'password' => 'password123',
            'role' => 'Salesperson',
        ])->assertCreated();

        $this->assertSame('Salesperson', $response->json('data.role'));

        // The new account can actually log in.
        $this->postJson('/api/auth/login', [
            'email' => 'karim@mobishop.test',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.roles.0', 'Salesperson');
    }

    public function test_user_without_permission_cannot_manage_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->actingAsAdmin();

        $this->putJson("/api/users/{$admin->id}", ['is_active' => false])
            ->assertUnprocessable();
    }

    public function test_admin_can_deactivate_another_user_and_they_lose_access(): void
    {
        $admin = $this->actingAsAdmin();

        // UserController::assertSameCompany() 404s any user whose
        // company_id doesn't match the acting admin's — User has no
        // BelongsToCompany scope to do this automatically, so the test
        // must wire it up explicitly, same as everywhere else.
        $staff = User::factory()->create(['company_id' => $admin->company_id]);
        $this->assignCompanyRole(Company::find($admin->company_id), $staff, 'Salesperson');

        $this->putJson("/api/users/{$staff->id}", ['is_active' => false])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_admin_can_reset_a_users_password(): void
    {
        $admin = $this->actingAsAdmin();
        $staff = User::factory()->create(['company_id' => $admin->company_id]);

        $this->postJson("/api/users/{$staff->id}/reset-password", ['password' => 'newpassword123'])
            ->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'newpassword123',
        ])->assertOk();
    }

    public function test_roles_endpoint_lists_seeded_roles(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/roles')->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('name')->contains('Salesperson'));
    }
}
