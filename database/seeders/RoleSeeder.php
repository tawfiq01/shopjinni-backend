<?php

namespace Database\Seeders;

use App\Domain\Auth\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Creates the demo company's own Admin/Salesperson/Accountant roles —
 * roles are per-company now (see CompanyTeamResolver), so there's no
 * global set left to rely on. Must run inside DatabaseSeeder's
 * CurrentCompany::forceFor() block, same as BranchSeeder/UserSeeder.
 * Mirrors CompanyProvisioningService::provision()'s role-creation step.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::DEFAULT_ROLE_PERMISSIONS as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->is_system = $name === 'Admin';
            $role->save();
            $role->syncPermissions($permissions);
        }
    }
}
