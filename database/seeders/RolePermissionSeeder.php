<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'catalog.manage',
            'distributors.manage',
            'accounting.manage',
            'accounting.view',
            'purchases.manage',
            'customers.manage',
            'pos.sell',
            'expenses.manage',
            'reports.view',
            'reports.view-cost',
            'users.manage',
            'branches.manage',
            'stock.transfer',
            'backup.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions);

        $accountant = Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
        $accountant->syncPermissions([
            'accounting.manage',
            'accounting.view',
            'expenses.manage',
            'reports.view',
            'reports.view-cost',
        ]);

        $salesperson = Role::firstOrCreate(['name' => 'Salesperson', 'guard_name' => 'web']);
        $salesperson->syncPermissions([
            'pos.sell',
            'customers.manage',
            'reports.view',
        ]);
    }
}
