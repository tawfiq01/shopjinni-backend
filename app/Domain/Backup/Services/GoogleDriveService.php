<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Models\BackupSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to Google's OAuth2 and Drive v3 REST APIs directly over HTTP
 * rather than through the official google/apiclient SDK — that package
 * bundles stub classes for every Google API (Drive, Sheets, Gmail, ...)
 * into one enormous download, which proved unreliable to install in this
 * environment. Both APIs are plain, stable JSON REST endpoints, so a thin
 * wrapper over Laravel's HTTP client covers everything this app needs
 * (auth URL, token exchange/refresh, folder listing, file upload) with no
 * extra dependency at all.
 */
class GoogleDriveService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const DRIVE_FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    private const DRIVE_UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    public function getAuthUrl(): string
    {
        $params = [
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => config('services.google_drive.redirect_uri'),
            'response_type' => 'code',
            'access_type' => 'offline',
            // Forces Google to re-issue a refresh_token even on repeat
            // consent — otherwise a reconnect after a disconnect silently
            // comes back without one.
            'prompt' => 'consent',
            // drive.file alone would only let the app see folders/files it
            // created itself — drive.metadata.readonly is also needed so
            // the folder picker can list the admin's *existing* folders.
            'scope' => 'https://www.googleapis.com/auth/drive.file '
                .'https://www.googleapis.com/auth/drive.metadata.readonly '
                .'https://www.googleapis.com/auth/userinfo.email',
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    public function handleCallback(string $code): void
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'redirect_uri' => config('services.google_drive.redirect_uri'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->extractError($response));
        }

        $token = $response->json();

        $email = Http::withToken($token['access_token'])
            ->get(self::USERINFO_URL)
            ->json('email');

        $setting = BackupSetting::current();
        $setting->update([
            'google_access_token' => $token['access_token'],
            // Google only sends a refresh_token on first consent (or when
            // prompt=consent forces re-consent); keep the existing one
            // otherwise rather than overwriting it with null.
            'google_refresh_token' => $token['refresh_token'] ?? $setting->google_refresh_token,
            'google_token_expires_at' => now()->addSeconds($token['expires_in']),
            'google_account_email' => $email,
        ]);
    }

    public function disconnect(): void
    {
        BackupSetting::current()->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
            'google_account_email' => null,
            'drive_folder_id' => null,
            'drive_folder_name' => null,
        ]);
    }

    /** @return array<int, array{id: string, name: string}> */
    public function listFolders(): array
    {
        $response = Http::withToken($this->validAccessToken())
            ->get(self::DRIVE_FILES_URL, [
                'q' => "mimeType='application/vnd.google-apps.folder' and trashed=false and 'me' in owners",
                'fields' => 'files(id, name)',
                'pageSize' => 200,
                'orderBy' => 'name',
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->extractError($response));
        }

        return $response->json('files', []);
    }

    /** @return array{id: string, size: int} */
    public function uploadFile(string $localPath, string $remoteName, ?string $folderId): array
    {
        $metadata = [
            'name' => $remoteName,
            'parents' => $folderId ? [$folderId] : [],
        ];

        $response = Http::withToken($this->validAccessToken())
            ->attach('metadata', json_encode($metadata), 'metadata.json', ['Content-Type' => 'application/json'])
            ->attach('file', file_get_contents($localPath), $remoteName, ['Content-Type' => 'application/zip'])
            ->post(self::DRIVE_UPLOAD_URL.'?uploadType=multipart&fields=id,size');

        if ($response->failed()) {
            throw new RuntimeException($this->extractError($response));
        }

        return [
            'id' => $response->json('id'),
            'size' => (int) $response->json('size', 0),
        ];
    }

    private function validAccessToken(): string
    {
        $setting = BackupSetting::current();

        if (! $setting->isGoogleConnected()) {
            throw new RuntimeException('Google Drive is not connected.');
        }

        $isExpired = $setting->google_token_expires_at === null
            || now()->greaterThanOrEqualTo($setting->google_token_expires_at);

        if (! $isExpired) {
            return $setting->google_access_token;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => $setting->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to refresh Google token: '.$this->extractError($response));
        }

        $token = $response->json();

        $setting->update([
            'google_access_token' => $token['access_token'],
            'google_token_expires_at' => now()->addSeconds($token['expires_in']),
        ]);

        return $token['access_token'];
    }

    private function extractError(Response $response): string
    {
        return $response->json('error_description')
            ?? $response->json('error.message')
            ?? $response->json('error')
            ?? 'Google API request failed with status '.$response->status();
    }
}
