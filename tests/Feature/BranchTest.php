<?php

namespace Tests\Feature;

use App\Domain\Branches\Models\Branch;
use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'branches.manage', 'guard_name' => 'web']);

        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['branches.manage']);
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
        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user, 'sanctum');

        Branch::factory()->create(['name' => 'Dhaka HQ', 'is_main' => true]);

        $this->getJson('/api/branches')->assertOk()->assertJsonCount(1, 'data');
    }
}
