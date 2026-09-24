<?php

namespace Tests\Feature;

use App\Domain\Auth\Support\PermissionCatalog;
use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function seedPermissions(): void
    {
        foreach (PermissionCatalog::ALL as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    /** @return array{0: Company, 1: User} */
    private function actingAsAdmin(string $companyName = 'Test Company'): array
    {
        $this->seedPermissions();

        $company = $this->createCompany($companyName);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['users.manage']);
        $this->actingAs($user, 'sanctum');

        return [$company, $user];
    }

    public function test_admin_can_create_a_custom_role_with_a_permission_subset(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/roles', [
            'name' => 'Cashier',
            'permissions' => ['pos.sell', 'customers.manage'],
        ])->assertCreated();

        $response->assertJsonPath('name', 'Cashier')
            ->assertJsonPath('is_system', false)
            ->assertJsonPath('user_count', 0);
        $this->assertEqualsCanonicalizing(['pos.sell', 'customers.manage'], $response->json('permissions'));
    }

    public function test_creating_a_role_rejects_an_unknown_permission_key(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/roles', [
            'name' => 'Cashier',
            'permissions' => ['not.a.real.permission'],
        ])->assertUnprocessable();
    }

    public function test_admin_can_edit_a_custom_roles_permissions(): void
    {
        [$company] = $this->actingAsAdmin();
        $role = $this->createCompanyRole($company, 'Cashier', ['pos.sell']);

        $response = $this->putJson("/api/roles/{$role->id}", [
            'permissions' => ['pos.sell', 'customers.manage'],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(['pos.sell', 'customers.manage'], $response->json('permissions'));
    }

    public function test_deleting_a_role_assigned_to_staff_is_rejected(): void
    {
        [$company] = $this->actingAsAdmin();
        $staff = User::factory()->create(['company_id' => $company->id]);
        $role = $this->assignCompanyRole($company, $staff, 'Cashier', ['pos.sell']);

        $this->deleteJson("/api/roles/{$role->id}")->assertStatus(409);
    }

    public function test_deleting_an_unused_custom_role_succeeds(): void
    {
        [$company] = $this->actingAsAdmin();
        $role = $this->createCompanyRole($company, 'Cashier', ['pos.sell']);

        $this->deleteJson("/api/roles/{$role->id}")->assertNoContent();
    }

    public function test_the_system_admin_role_cannot_be_deleted(): void
    {
        [, $admin] = $this->actingAsAdmin();
        $adminRole = $admin->roles()->first();

        $this->assertTrue((bool) $adminRole->is_system);
        $this->deleteJson("/api/roles/{$adminRole->id}")->assertStatus(409);
    }

    public function test_a_companys_roles_are_isolated_from_another_companys(): void
    {
        [$companyA] = $this->actingAsAdmin('Shop A');
        $roleA = $this->createCompanyRole($companyA, 'Cashier', ['pos.sell']);

        [$companyB] = $this->actingAsAdmin('Shop B');

        // Shop B's own role list never contains Shop A's "Cashier".
        $list = $this->getJson('/api/roles')->assertOk();
        $this->assertFalse(collect($list->json('data'))->pluck('name')->contains('Cashier'));

        // Shop B can create its OWN role named "Cashier" with no collision.
        $this->postJson('/api/roles', ['name' => 'Cashier', 'permissions' => ['pos.sell']])->assertCreated();

        // Shop B cannot see, edit, or delete Shop A's role by id.
        $this->putJson("/api/roles/{$roleA->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/roles/{$roleA->id}")->assertNotFound();
    }

    public function test_login_response_includes_the_owners_role_and_full_permission_set(): void
    {
        $this->seedPermissions();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password123',
            'company_name' => 'Fresh Shop',
        ])->assertCreated();

        // Regression test for a bug found while retrofitting teams mode:
        // AuthController::formatUser() built roles/permissions OUTSIDE its
        // CurrentCompany::forceFor() override, so a fresh registration's
        // response silently came back with empty roles/permissions.
        $this->assertContains('Admin', $response->json('user.roles'));
        $this->assertNotEmpty($response->json('user.permissions'));
        $this->assertContains('company.manage', $response->json('user.permissions'));

        $login = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com', 'password' => 'password123',
        ])->assertOk();
        $this->assertContains('Admin', $login->json('user.roles'));
        $this->assertNotEmpty($login->json('user.permissions'));
    }
}
