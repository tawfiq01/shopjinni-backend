<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Companies\Models\Company;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAccountant(): User
    {
        Permission::firstOrCreate(['name' => 'expenses.manage', 'guard_name' => 'web']);

        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Accountant', ['expenses.manage']);
        $this->actingAs($user, 'sanctum');

        // Seeded only now, after actingAs() — both ChartOfAccount and
        // ExpenseCategory are tenant-scoped models.
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCategorySeeder::class);

        return $user;
    }

    public function test_default_categories_are_seeded_with_their_own_gl_accounts(): void
    {
        $this->actingAsAccountant();

        $response = $this->getJson('/api/expense-categories')->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Rent'));
        $this->assertTrue($names->contains('Salary'));
    }

    public function test_creating_a_custom_category_creates_a_new_gl_account(): void
    {
        $this->actingAsAccountant();

        $response = $this->postJson('/api/expense-categories', ['name' => 'Bank Charges'])
            ->assertCreated();

        $this->assertNotNull($response->json('data.account_code'));
        $this->assertStringStartsWith('51', $response->json('data.account_code'));
    }

    public function test_recording_an_expense_posts_a_balanced_entry_against_cash(): void
    {
        $this->actingAsAccountant();
        $rent = ExpenseCategory::where('name', 'Rent')->firstOrFail();
        $cash = ChartOfAccount::where('code', '1000')->firstOrFail();

        $this->postJson('/api/expenses', [
            'expense_category_id' => $rent->id,
            'date' => now()->toDateString(),
            'amount' => 15000,
            'payment_account_id' => $cash->id,
            'description' => 'September shop rent',
        ])->assertCreated();

        $this->assertEquals(15000.0, $rent->account->balance());
        $this->assertEquals(-15000.0, $cash->fresh()->balance());
    }

    public function test_expenses_manage_permission_is_required(): void
    {
        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user, 'sanctum');

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCategorySeeder::class);

        $this->getJson('/api/expense-categories')->assertForbidden();
    }
}
