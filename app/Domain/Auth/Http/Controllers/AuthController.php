<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Services\GoogleAuthService;
use App\Domain\Companies\Services\CompanyProvisioningService;
use App\Domain\Companies\Support\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly CompanyProvisioningService $provisioning,
        private readonly GoogleAuthService $google,
    ) {}

    /**
     * Self-service sign-up: creates a brand-new, fully isolated company
     * (branch, chart of accounts, payment methods) and its owner in one
     * step, then logs them straight in — same response shape as login().
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        $this->provisioning->provision(
            $data['company_name'],
            $data['company_address'] ?? null,
            $data['company_phone'] ?? null,
            $user,
        );

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user->fresh()),
        ], 201);
    }

    /**
     * Returns the Google consent URL for the Flutter app to open. The
     * callback below always bounces the browser back to config('services.
     * frontend.url') — a fixed value, not something this endpoint or the
     * caller controls, so this isn't an open redirect.
     */
    public function googleRedirect()
    {
        return response()->json(['url' => $this->google->getAuthUrl()]);
    }

    /**
     * Public (no auth:sanctum) — Google redirects the browser here itself.
     * Finds-or-creates a User by the verified Google email, issues a
     * Sanctum token either way, and hands the browser back to the SPA with
     * that token in the URL for it to pick up. A brand-new user has no
     * company yet (company_id stays null) — the SPA's router is
     * responsible for then routing them to onboarding instead of the
     * dashboard, and /auth/onboarding/complete enforces that server-side
     * too, so this endpoint doesn't need to know or care which case it is.
     */
    public function googleCallback(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);

        $frontendUrl = rtrim(config('services.frontend.url'), '/');

        try {
            $identity = $this->google->resolveIdentity($request->string('code')->toString());
        } catch (\Throwable $e) {
            return redirect()->away($frontendUrl.'/#/login?google_error='.urlencode($e->getMessage()));
        }

        $user = User::where('email', $identity['email'])->first();

        if (! $user) {
            $user = User::create([
                'name' => $identity['name'] ?? $identity['email'],
                'email' => $identity['email'],
                // Random password: this account only ever signs in via
                // Google, but the column is NOT NULL and must never be
                // guessable.
                'password' => Hash::make(str()->random(40)),
                'is_active' => true,
            ]);
        }

        if (! $user->is_active) {
            return redirect()->away($frontendUrl.'/#/login?google_error='.urlencode('This account has been deactivated.'));
        }

        // company_id !== null must be checked FIRST: a bare
        // $user->company?->is_active is null (falsy) for a company-less
        // Super Admin, which would otherwise lock out their own login.
        if ($user->company_id !== null && ! $user->company?->is_active) {
            return redirect()->away($frontendUrl.'/#/login?google_error='.urlencode('This shop has been deactivated. Contact support.'));
        }

        $token = $user->createToken('google-web')->plainTextToken;

        return redirect()->away($frontendUrl.'/#/auth/callback?token='.urlencode($token));
    }

    /**
     * Completes sign-up for a user who arrived via Google with no company
     * yet. Rejects (409) if they already have one — the SPA's router
     * shouldn't be the only thing standing between a user and provisioning
     * a second company for the same account, which this app doesn't
     * support (one company per user, for now).
     */
    public function completeOnboarding(Request $request)
    {
        if ($request->user()->company_id !== null) {
            return response()->json([
                'message' => 'This account already belongs to a company.',
            ], 409);
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:255'],
        ]);

        $this->provisioning->provision(
            $data['company_name'],
            $data['company_address'] ?? null,
            $data['company_phone'] ?? null,
            $request->user(),
        );

        return response()->json([
            'user' => $this->formatUser($request->user()->fresh()),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        // company_id !== null must be checked FIRST: a bare
        // $user->company?->is_active is null (falsy) for a company-less
        // Super Admin, which would otherwise lock out their own login.
        if ($user->company_id !== null && ! $user->company?->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This shop has been deactivated. Contact support.'],
            ]);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $request->user()->update(['password' => Hash::make($data['new_password'])]);

        return response()->json(['message' => 'Password updated.']);
    }

    private function formatUser(User $user): array
    {
        // Branch/Company (BelongsToCompany-scoped) and roles/permissions
        // (spatie "team"-scoped, team = company — see CompanyTeamResolver)
        // both read the current company from Auth::user() by default —
        // but login()/register() format the response in the same request
        // that ISSUES the token, before Sanctum has anything to
        // authenticate from, so Auth::user() is still null here even
        // though $user is right. The ENTIRE return has to be built inside
        // this override, not just the loadMissing() call — roles/
        // permissions were once (silently) built outside it, which meant
        // a fresh login/register response always came back with empty
        // roles/permissions the moment teams mode went live, since
        // CurrentCompany::id() falls back to the (still-null) Auth::user()
        // as soon as the callback returns.
        return CurrentCompany::forceFor($user->company_id, function () use ($user) {
            $user->loadMissing('branch', 'company');

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'branch_id' => $user->branch_id,
                'branch_name' => $user->branch?->name,
                'company_id' => $user->company_id,
                'company_name' => $user->company?->name,
                'is_super_admin' => $user->is_super_admin,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ];
        });
    }
}
