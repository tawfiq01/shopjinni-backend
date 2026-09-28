<?php

namespace App\Http\Middleware;

use App\Domain\Subscriptions\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied globally (see routes/api.php) rather than opt-in per route
 * group — BelongsToCompany's whole design principle is "the unsafe
 * direction is forgetting to filter," and an opt-in gate here would
 * leave every future route group unprotected by default. Fails closed
 * on a missing Subscription row, same fail-closed posture as
 * BelongsToCompany's own scope.
 */
class EnsureSubscriptionActive
{
    /** Checked first, before loading anything — these must always work,
     *  including for a shop that's actually blocked (so it can see its
     *  own status and self-serve pay), and for auth itself. */
    private const ALWAYS_ALLOWED = [
        'api/auth/*',
        'api/company',
        'api/company/subscription*',
        'api/company/setup-wizard*',
        'api/subscription/plans',
        'api/logo/*',
        'api/admin/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_super_admin || $user?->company_id === null) {
            return $next($request);
        }

        if ($request->is(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $subscription = Subscription::where('company_id', $user->company_id)->first();

        if ($subscription === null || $subscription->isBlocked()) {
            return response()->json([
                'message' => "Your shop's subscription is not active. Please renew to continue.",
                'subscription_status' => $subscription?->status ?? 'missing',
            ], 403);
        }

        return $next($request);
    }
}
