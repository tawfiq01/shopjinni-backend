<?php

namespace App\Domain\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function show(Request $request)
    {
        return $this->formatted($request->user()->company, $request);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:255'],
        ]);

        $company = $request->user()->company;
        $company->update($data);

        return $this->formatted($company, $request);
    }

    public function completeSetupWizard(Request $request)
    {
        $company = $request->user()->company;
        // Deliberately not in $fillable (a mass-updatable timestamp a
        // future generic "update company" call could accidentally reset
        // is worse than one extra explicit assignment here) — set and
        // saved directly instead of via update().
        $company->setup_wizard_completed_at = now();
        $company->save();

        return $this->formatted($company, $request);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $company = $request->user()->company;

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }

        $path = $request->file('logo')->storeAs(
            'logos',
            $company->id.'-'.Str::random(8).'.'.$request->file('logo')->extension(),
            'public',
        );

        $company->update(['logo_path' => $path]);

        return $this->formatted($company->fresh(), $request);
    }

    public function deleteLogo(Request $request)
    {
        $company = $request->user()->company;

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
        }

        return $this->formatted($company->fresh(), $request);
    }

    /**
     * Public (no auth:sanctum): a Flutter Web <img>/NetworkImage load can't
     * attach an Authorization header, and the filename itself is already
     * an unguessable {company_id}-{random8}.{ext} token — same posture as
     * a public S3 asset URL. Served through an actual Laravel route
     * (rather than the raw /storage/* symlink) specifically so CORS
     * headers apply: PHP's built-in dev server serves an existing static
     * file directly, bypassing the framework (and its CORS middleware)
     * entirely, and a cross-origin NetworkImage fetch (the app on :5173,
     * the API on :8000) is blocked without that header regardless of dev
     * server vs. production.
     */
    public function logoImage(string $filename)
    {
        $path = 'logos/'.basename($filename);

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return response(Storage::disk('public')->get($path))
            ->header('Content-Type', Storage::disk('public')->mimeType($path))
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function formatted($company, Request $request): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'owner_name' => $company->owner_name,
            'address' => $company->address,
            'district' => $company->district,
            'country' => $company->country,
            'currency' => $company->currency,
            'timezone' => $company->timezone,
            'phone' => $company->phone,
            'setup_wizard_completed' => $company->setup_wizard_completed_at !== null,
            // Built from the actual incoming request host rather than the
            // static APP_URL config — the app is reachable at more than
            // one host (localhost for the dev machine itself, its LAN IP
            // for other devices), and a hardcoded APP_URL would only ever
            // produce a working image URL for one of them.
            'logo_url' => $company->logo_path
                ? $request->getSchemeAndHttpHost().'/api/logo/'.basename($company->logo_path)
                : null,
        ];
    }
}
