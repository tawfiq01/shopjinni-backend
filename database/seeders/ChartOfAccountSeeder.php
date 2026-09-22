<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash in Hand', 'type' => 'asset'],
            ['code' => '1010', 'name' => 'Bank', 'type' => 'asset'],
            ['code' => '1020', 'name' => 'bKash', 'type' => 'asset'],
            ['code' => '1030', 'name' => 'Nagad', 'type' => 'asset'],
            ['code' => '1040', 'name' => 'Card / Other MFS', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability'],
            ['code' => '2100', 'name' => 'VAT / Tax Payable', 'type' => 'liability'],
            ['code' => '3000', 'name' => "Owner's Equity", 'type' => 'equity'],
            ['code' => '3100', 'name' => 'Opening Balance Equity', 'type' => 'equity'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'income'],
            ['code' => '4100', 'name' => 'Other Income', 'type' => 'income'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'Operating Expenses', 'type' => 'expense'],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::firstOrCreate(
                ['code' => $account['code']],
                [...$account, 'is_system' => true, 'is_active' => true]
            );
        }
    }
}
