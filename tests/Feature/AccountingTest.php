<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Services\AccountingService;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private function seedCoreAccounts(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function actingAsAccountant(): User
    {
        Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
        $role->givePermissionTo(['accounting.manage', 'accounting.view']);

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_service_rejects_unbalanced_entries(): void
    {
        $this->seedCoreAccounts();
        $cash = ChartOfAccount::where('code', '1000')->firstOrFail();
        $sales = ChartOfAccount::where('code', '4000')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        app(AccountingService::class)->postEntry(
            lines: [
                ['account_id' => $cash->id, 'debit' => 100],
                ['account_id' => $sales->id, 'credit' => 90],
            ],
            narration: 'Unbalanced test',
        );
    }

    public function test_service_posts_a_balanced_entry_and_updates_balances(): void
    {
        $this->seedCoreAccounts();
        $cash = ChartOfAccount::where('code', '1000')->firstOrFail();
        $sales = ChartOfAccount::where('code', '4000')->firstOrFail();

        app(AccountingService::class)->postEntry(
            lines: [
                ['account_id' => $cash->id, 'debit' => 500],
                ['account_id' => $sales->id, 'credit' => 500],
            ],
            narration: 'Cash sale',
        );

        $this->assertSame(500.0, $cash->fresh()->balance());
        $this->assertSame(500.0, $sales->fresh()->balance());
    }

    public function test_api_rejects_manual_entry_without_permission(): void
    {
        $this->seedCoreAccounts();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $cash = ChartOfAccount::where('code', '1000')->firstOrFail();
        $sales = ChartOfAccount::where('code', '4000')->firstOrFail();

        $this->postJson('/api/accounting/journal-entries', [
            'entry_date' => now()->toDateString(),
            'narration' => 'Should be blocked',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 100],
                ['account_id' => $sales->id, 'credit' => 100],
            ],
        ])->assertForbidden();
    }

    public function test_api_posts_a_balanced_manual_entry(): void
    {
        $this->seedCoreAccounts();
        $this->actingAsAccountant();

        $cash = ChartOfAccount::where('code', '1000')->firstOrFail();
        $equity = ChartOfAccount::where('code', '3100')->firstOrFail();

        $this->postJson('/api/accounting/journal-entries', [
            'entry_date' => now()->toDateString(),
            'narration' => 'Opening capital',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 50000],
                ['account_id' => $equity->id, 'credit' => 50000],
            ],
        ])->assertCreated();

        $ledger = $this->getJson("/api/accounting/accounts/{$cash->id}/ledger")
            ->assertOk()
            ->json('lines');

        $this->assertCount(1, $ledger);
        $this->assertEquals(50000.0, $ledger[0]['running_balance']);
    }

    public function test_api_rejects_unbalanced_manual_entry_with_422(): void
    {
        $this->seedCoreAccounts();
        $this->actingAsAccountant();

        $cash = ChartOfAccount::where('code', '1000')->firstOrFail();
        $equity = ChartOfAccount::where('code', '3100')->firstOrFail();

        $this->postJson('/api/accounting/journal-entries', [
            'entry_date' => now()->toDateString(),
            'narration' => 'Bad entry',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 100],
                ['account_id' => $equity->id, 'credit' => 90],
            ],
        ])->assertUnprocessable();
    }
}
