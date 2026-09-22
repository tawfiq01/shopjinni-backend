<?php

use App\Domain\Backup\Http\Controllers\GoogleDriveCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Google redirects the browser here directly after OAuth consent — it is
// not an API/JSON endpoint, so it lives on the web (session/cookie-less)
// router rather than routes/api.php.
Route::get('/backup/google-callback', GoogleDriveCallbackController::class);
