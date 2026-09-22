<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Purchasing\Models\Distributor;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DistributorTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'distributors.manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('distributors.manage');

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_creating_a_distributor_with_opening_balance_posts_a_balanced_entry(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/distributors', [
            'name' => 'ABC Mobile Distributors',
            'mobile' => '01711111111',
            'opening_balance' => 25000,
        ])->assertCreated();

        $distributor = Distributor::findOrFail($response->json('data.id'));
        $this->assertSame(25000.0, $distributor->currentBalance());

        $payable = ChartOfAccount::where('code', '2000')->firstOrFail();
        $this->assertSame(25000.0, $payable->balance());
    }

    public function test_distributor_with_zero_opening_balance_posts_no_entry(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/distributors', [
            'name' => 'No Balance Traders',
            'mobile' => '01822222222',
        ])->assertCreated();

        $this->assertEquals(0.0, $response->json('data.current_balance'));
    }

    public function test_distributor_cannot_be_deleted_once_it_has_ledger_history(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->actingAsAdmin();

        $id = $this->postJson('/api/distributors', [
            'name' => 'Has History Ltd',
            'mobile' => '01933333333',
            'opening_balance' => 5000,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/distributors/{$id}")->assertStatus(409);
    }

    public function test_user_without_permission_cannot_create_a_distributor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/distributors', [
            'name' => 'Blocked Co',
            'mobile' => '01999999999',
        ])->assertForbidden();
    }
}
