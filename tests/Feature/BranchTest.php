<?php

namespace Tests\Feature;

use App\Domain\Branches\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'branches.manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('branches.manage');

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_admin_can_create_a_branch(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/branches', ['name' => 'Chattogram Branch'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chattogram Branch')
            ->assertJsonPath('data.is_main', false);
    }

    public function test_user_without_permission_cannot_create_a_branch(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/branches', ['name' => 'Sylhet Branch'])->assertForbidden();
    }

    public function test_any_authenticated_user_can_list_branches(): void
    {
        Branch::factory()->create(['name' => 'Dhaka HQ', 'is_main' => true]);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/branches')->assertOk()->assertJsonCount(1, 'data');
    }
}
