<?php

use App\Domain\Auth\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data migration, not a seeder — same reasoning as
     * 2026_09_26_000100_seed_default_subscription_plans.php: `php artisan
     * migrate` alone (no `db:seed`) is what actually runs in production,
     * and CompanyProvisioningService::provision() calls
     * $role->syncPermissions() for every new company, which throws
     * PermissionDoesNotExist if these rows aren't here yet. Previously
     * only RolePermissionSeeder created them, so a migrate-only deploy
     * would crash on the very first signup.
     */
    public function up(): void
    {
        $now = now();

        foreach (PermissionCatalog::ALL as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', PermissionCatalog::ALL)
            ->where('guard_name', 'web')
            ->delete();
    }
};
