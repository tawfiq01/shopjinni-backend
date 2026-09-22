<?php

namespace App\Domain\Backup\Http\Controllers;

use App\Domain\Backup\Services\GoogleDriveService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Throwable;

class GoogleDriveCallbackController extends Controller
{
    public function __invoke(Request $request, GoogleDriveService $drive)
    {
        if ($request->filled('error')) {
            return $this->page('Connection cancelled', $request->string('error'));
        }

        if (! $request->filled('code')) {
            return $this->page('Something went wrong', 'No authorization code was returned by Google.');
        }

        try {
            $drive->handleCallback($request->string('code'));
        } catch (Throwable $e) {
            return $this->page('Connection failed', $e->getMessage());
        }

        return $this->page('Google Drive connected', 'You can close this tab and return to MobiShop.');
    }

    private function page(string $title, string $message)
    {
        $safeTitle = e($title);
        $safeMessage = e($message);

        return response(<<<HTML
            <!DOCTYPE html>
            <html>
                <head><title>{$safeTitle}</title></head>
                <body style="font-family: sans-serif; text-align: center; padding-top: 4rem;">
                    <h2>{$safeTitle}</h2>
                    <p>{$safeMessage}</p>
                </body>
            </html>
            HTML);
    }
}
