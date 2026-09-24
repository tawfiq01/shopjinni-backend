<?php

namespace App\Domain\Companies\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Resolves which company the current request/console context is scoped
 * to. Normally reads the currently authenticated user's company_id, so
 * there is no per-request state to set up or reset — this is what makes
 * it safe across ordinary requests and tests.
 *
 * Console commands, seeders, and company-provisioning code have no
 * authenticated HTTP user to derive a company from, so `forceFor()`
 * provides a scoped, stack-based override for exactly those contexts.
 * Never call it from request-handling code — Auth::user() is the source
 * of truth there.
 *
 * Returns null (and BelongsToCompany then returns zero rows, never "no
 * filter") when neither an override nor an authenticated user resolves a
 * company — e.g. a signed-up-but-not-yet-onboarded account.
 */
class CurrentCompany
{
    private static ?int $override = null;

    public static function id(): ?int
    {
        return self::$override ?? Auth::user()?->company_id;
    }

    public static function forceFor(?int $companyId, callable $callback): mixed
    {
        $previous = self::$override;
        self::$override = $companyId;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }
}
