<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Customers\Models\Customer;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'customers.manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('customers.manage');

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_creating_a_customer_with_opening_balance_posts_a_balanced_entry(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/customers', [
            'name' => 'Karim Hossain',
            'mobile' => '01711111111',
            'opening_balance' => 3000,
        ])->assertCreated();

        $customer = Customer::findOrFail($response->json('data.id'));
        $this->assertSame(3000.0, $customer->currentBalance());

        $receivable = ChartOfAccount::where('code', '1100')->firstOrFail();
        $this->assertSame(3000.0, $receivable->balance());
    }

    public function test_customer_with_zero_opening_balance_posts_no_entry(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/customers', [
            'name' => 'Walk-in Customer',
            'mobile' => '01822222222',
        ])->assertCreated();

        $this->assertEquals(0.0, $response->json('data.current_balance'));
    }

    public function test_customer_cannot_be_deleted_once_it_has_ledger_history(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->actingAsAdmin();

        $id = $this->postJson('/api/customers', [
            'name' => 'Has History',
            'mobile' => '01933333333',
            'opening_balance' => 1000,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/customers/{$id}")->assertStatus(409);
    }

    public function test_user_without_permission_cannot_create_a_customer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/customers', [
            'name' => 'Blocked',
            'mobile' => '01999999999',
        ])->assertForbidden();
    }
}
