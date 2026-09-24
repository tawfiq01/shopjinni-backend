<?php

namespace App\Domain\Auth\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sign-in-with-Google: exchanges an authorization code for the signer's
 * email/name and nothing else. Modeled on GoogleDriveService's proven
 * raw-HTTP pattern (installing the google/apiclient SDK failed repeatedly
 * on this machine — see that class's docblock), but deliberately lighter:
 * no offline access, no refresh token, nothing persisted here — a login
 * flow only needs the identity, not standing API access.
 */
class GoogleAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public function getAuthUrl(): string
    {
        $params = [
            'client_id' => config('services.google_login.client_id'),
            'redirect_uri' => config('services.google_login.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    /** @return array{email: string, name: ?string} */
    public function resolveIdentity(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_login.client_id'),
            'client_secret' => config('services.google_login.client_secret'),
            'redirect_uri' => config('services.google_login.redirect_uri'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->extractError($response));
        }

        $accessToken = $response->json('access_token');

        $profile = Http::withToken($accessToken)->get(self::USERINFO_URL);

        if ($profile->failed()) {
            throw new RuntimeException($this->extractError($profile));
        }

        $email = $profile->json('email');

        if (! $email) {
            throw new RuntimeException('Google did not return an email address for this account.');
        }

        return [
            'email' => $email,
            'name' => $profile->json('name'),
        ];
    }

    private function extractError(Response $response): string
    {
        return $response->json('error_description')
            ?? $response->json('error.message')
            ?? $response->json('error')
            ?? 'Google API request failed with status '.$response->status();
    }
}
