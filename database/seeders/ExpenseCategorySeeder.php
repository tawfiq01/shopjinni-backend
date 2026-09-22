<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Expenses\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $parent = ChartOfAccount::where('code', '5100')->first();
        if (! $parent) {
            return;
        }

        $names = ['Rent', 'Salary', 'Electricity', 'Internet', 'Transport', 'Marketing', 'Repair', 'Miscellaneous'];

        foreach ($names as $index => $name) {
            if (ExpenseCategory::where('name', $name)->exists()) {
                continue;
            }

            $code = '51'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

            $account = ChartOfAccount::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => 'expense', 'parent_id' => $parent->id, 'is_system' => true, 'is_active' => true]
            );

            ExpenseCategory::create(['name' => $name, 'chart_of_account_id' => $account->id]);
        }
    }
}
