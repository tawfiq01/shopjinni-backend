<?php

namespace Database\Seeders;

use App\Domain\Auth\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Permissions are global capability strings and stay unscoped — only
 * Role is per-company now (see CompanyTeamResolver / config/permission.php).
 * There's no more single global "Admin" role to seed here:
 * CompanyProvisioningService creates each company's own Admin/Salesperson/
 * Accountant roles from PermissionCatalog::DEFAULT_ROLE_PERMISSIONS.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::ALL as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
