<?php

namespace App\Domain\Auth\Support;

use App\Domain\Companies\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Makes spatie's "team" concept the same thing as CurrentCompany — a
 * company's Role rows are its "team". Deliberately stateless: unlike
 * spatie's own DefaultTeamResolver (which caches a team id on a property
 * and therefore needs setPermissionsTeamId() called every request),
 * getPermissionsTeamId() re-reads CurrentCompany::id() fresh on every
 * call, so it correctly observes CurrentCompany::forceFor() overrides
 * (e.g. during company provisioning) with no middleware wiring needed.
 * setPermissionsTeamId() is a no-op — there's nothing here to set.
 *
 * Instantiated by spatie itself via `new (config('permission.team_resolver'))`
 * (a bare `new $class`, not container-resolved), so this must have a
 * zero-argument constructor — which it does, implicitly.
 */
class CompanyTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): int|string|null
    {
        return CurrentCompany::id();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        // No-op — see class docblock.
    }
}
