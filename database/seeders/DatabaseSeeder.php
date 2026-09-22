<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            BranchSeeder::class,
            UserSeeder::class,
            ProductTypeSeeder::class,
            ChartOfAccountSeeder::class,
            PaymentMethodSeeder::class,
            ExpenseCategorySeeder::class,
        ]);
    }
}
