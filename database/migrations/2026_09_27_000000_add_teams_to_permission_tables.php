<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrofits spatie/laravel-permission's "teams" feature onto tables
     * that were originally created with teams off — so, unlike a fresh
     * install, this has to carry real existing data across the change
     * instead of just adding empty columns. Order matters a lot here:
     * nullable schema first, then a data backfill (raw DB:: queries, not
     * Eloquent — a migration has no authenticated user for the new team
     * resolver to read), and only THEN the composite unique/primary key
     * constraints that make a "team" (company) actually own its rows.
     *
     * Deliberately does NOT use spatie's own reference stub's
     * `->default('1')` for the pivot tables' team_id columns — applied to
     * this app's real data, that would silently re-point every existing
     * company's role assignments into whichever company happens to be
     * id 1.
     *
     * Idempotency note: the ORIGINAL create_permission_tables migration
     * reads config('permission.teams') live, at whatever moment it
     * actually runs — it isn't a frozen snapshot. In production that
     * migration already ran (recorded, never re-executed) back when
     * teams was false, so this retrofit is genuinely needed there. But
     * `RefreshDatabase`-based tests re-run every migration from an empty
     * DB on every run, and by then config/permission.php already says
     * teams=true — so in tests, that ORIGINAL migration builds the
     * team-scoped shape (team_id column, team-scoped unique/PK) itself,
     * with nothing left for this migration to retrofit. Guard every step
     * on whether the old (teamless) shape is actually still there.
     */
    public function up(): void
    {
        $needsRetrofit = ! Schema::hasColumn('roles', 'team_id');

        if ($needsRetrofit) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->after('id');
            });
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->after('role_id');
            });
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->after('permission_id');
            });

            // Dropped BEFORE the backfill, not after: the backfill inserts
            // one "Admin"/"Salesperson"/"Accountant" row per EXISTING
            // company, and the old constraint (just [name, guard_name],
            // team_id not part of it yet) would reject the second
            // company's "Admin" as a duplicate of the first's.
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique('roles_name_guard_name_unique');
            });
        }

        // is_system is our own column, not part of spatie's shape at
        // all — needed unconditionally, independent of the above.
        if (! Schema::hasColumn('roles', 'is_system')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_system')->default(false)->after('guard_name');
            });
        }

        if ($needsRetrofit) {
            $this->backfillPerCompanyRoles();

            Schema::table('roles', function (Blueprint $table) {
                $table->unique(['team_id', 'name', 'guard_name']);
            });

            // MySQL refuses to drop a PRIMARY KEY while a foreign key
            // still depends on it for its supporting index (role_id/
            // permission_id are each the leading column of their table's
            // current primary key) — the FK has to come off first and go
            // back on after the primary key is rebuilt to include team_id.
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->dropForeign('model_has_roles_role_id_foreign');
                $table->dropPrimary();
                $table->primary(['team_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
                $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            });
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->dropForeign('model_has_permissions_permission_id_foreign');
                $table->dropPrimary();
                $table->primary(['team_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
                $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            });

            // The backfill above writes via raw DB:: queries, which bypass
            // Eloquent model events entirely — spatie's own cache is
            // normally invalidated by Role/Permission model save/delete
            // events, so without this the permission cache silently keeps
            // serving pre-migration data (every permission check 403s,
            // even for an Admin who very much does have the permission)
            // until it naturally expires. Same call spatie's own
            // create_permission_tables migration makes at the end of its
            // own up().
            app('cache')
                ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
                ->forget(config('permission.cache.key'));
        }
    }

    /**
     * Clones each of the 3 pre-existing GLOBAL roles into a company-owned
     * copy for every company that already exists, re-points that
     * company's users' role assignments onto the clone, then removes the
     * now-fully-migrated global rows. A fresh install (no companies, no
     * global roles yet — RolePermissionSeeder no longer creates any) is a
     * clean no-op.
     */
    private function backfillPerCompanyRoles(): void
    {
        $now = now();
        $companyIds = DB::table('companies')->pluck('id');
        $globalRoles = DB::table('roles')->whereNull('team_id')->get();

        foreach ($globalRoles as $globalRole) {
            $permissionIds = DB::table('role_has_permissions')
                ->where('role_id', $globalRole->id)
                ->pluck('permission_id');

            foreach ($companyIds as $companyId) {
                $newRoleId = DB::table('roles')->insertGetId([
                    'team_id' => $companyId,
                    'name' => $globalRole->name,
                    'guard_name' => $globalRole->guard_name,
                    'is_system' => $globalRole->name === 'Admin',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($permissionIds->isNotEmpty()) {
                    DB::table('role_has_permissions')->insert(
                        $permissionIds->map(fn ($permissionId) => [
                            'role_id' => $newRoleId,
                            'permission_id' => $permissionId,
                        ])->all()
                    );
                }

                $companyUserIds = DB::table('users')->where('company_id', $companyId)->pluck('id');

                DB::table('model_has_roles')
                    ->where('role_id', $globalRole->id)
                    ->where('model_type', 'App\\Models\\User')
                    ->whereIn('model_id', $companyUserIds)
                    ->update(['role_id' => $newRoleId, 'team_id' => $companyId]);
            }

            // Anything still pointing at the global role at this point
            // belongs to a user with no company (shouldn't exist in
            // practice — roles are only ever assigned post-provisioning —
            // but dropped defensively rather than left dangling).
            DB::table('model_has_roles')->where('role_id', $globalRole->id)->delete();
            DB::table('role_has_permissions')->where('role_id', $globalRole->id)->delete();
            DB::table('roles')->where('id', $globalRole->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name', 'guard_name']);
            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
            $table->dropColumn(['team_id', 'is_system']);
        });
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropForeign('model_has_roles_role_id_foreign');
            $table->dropPrimary();
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->dropColumn('team_id');
        });
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropForeign('model_has_permissions_permission_id_foreign');
            $table->dropPrimary();
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->dropColumn('team_id');
        });
    }
};
