<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['name' => 'Cash', 'account_code' => '1000'],
            ['name' => 'Bank', 'account_code' => '1010'],
            ['name' => 'bKash', 'account_code' => '1020'],
            ['name' => 'Nagad', 'account_code' => '1030'],
            ['name' => 'Card / Other MFS', 'account_code' => '1040'],
        ];

        foreach ($methods as $method) {
            $account = ChartOfAccount::where('code', $method['account_code'])->first();
            if (! $account) {
                continue;
            }

            PaymentMethod::firstOrCreate(
                ['name' => $method['name']],
                ['chart_of_account_id' => $account->id, 'is_active' => true]
            );
        }
    }
}
