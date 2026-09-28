<?php

namespace Database\Seeders;

use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Support\CurrentCompany;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately does NOT use WithoutModelEvents: BelongsToCompany's
    // auto-fill of company_id runs on the Eloquent `creating` event, so
    // suppressing model events here would leave every seeded row with a
    // null company_id.

    /**
     * Seed the application's database: roles/permissions are global, but
     * everything else belongs to one demo company, provisioned the same
     * way self-service registration provisions a real one.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $company = Company::create(['name' => 'Demo Shop']);

        CurrentCompany::forceFor($company->id, function () {
            $this->call([
                BranchSeeder::class,
                RoleSeeder::class,
                UserSeeder::class,
                ProductTypeSeeder::class,
                ColorSeeder::class,
                ChartOfAccountSeeder::class,
                PaymentMethodSeeder::class,
                ExpenseCategorySeeder::class,
            ]);
        });
    }
}
