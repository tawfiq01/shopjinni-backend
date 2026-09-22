<?php

namespace Tests\Feature;

use App\Domain\Branches\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        foreach (['users.manage'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'Salesperson', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('users.manage');

        $user = User::factory()->create();
        $user->assignRole($role);
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
        $this->actingAsAdmin();
        Role::firstOrCreate(['name' => 'Salesperson', 'guard_name' => 'web']);

        $staff = User::factory()->create();
        $staff->assignRole('Salesperson');

        $this->putJson("/api/users/{$staff->id}", ['is_active' => false])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_admin_can_reset_a_users_password(): void
    {
        $this->actingAsAdmin();
        $staff = User::factory()->create();

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
        $this->assertTrue(collect($response->json('data'))->contains('Salesperson'));
    }
}
