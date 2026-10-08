<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The actual backup time is admin-configurable at runtime (stored in the
// backup_settings row), so this can't be a fixed ->dailyAt() entry — the
// command itself checks the clock against that stored time every minute.
// Requires a system cron (or Windows Task Scheduler) entry running
// `php artisan schedule:run` every minute on whatever server this deploys
// to — that part is outside the app and must be set up on the host.
Schedule::command('backup:check-schedule')->everyMinute();

Schedule::command('subscriptions:check-status')->daily();

// Debugging aid for "IMEI not found" reports on hosts where `tinker` is
// unusable (shared hosting commonly disables the Phar extension that
// psysh/tinker needs to boot, independent of whether this app uses phars).
// Bypasses the per-company scope so a mismatched company_id is visible
// instead of silently looking like "no such IMEI".
Artisan::command('imei:check {imei}', function (string $imei) {
    $rows = \App\Domain\Inventory\Models\ImeiUnit::withoutGlobalScopes()
        ->where('imei1', $imei)
        ->orWhere('imei2', $imei)
        ->get(['id', 'company_id', 'imei1', 'imei2', 'status']);

    $this->info($rows->count().' row(s) found for that IMEI (ignoring the company filter):');
    foreach ($rows as $row) {
        $this->line($row->toJson());
    }
})->purpose('Look up an IMEI across all companies, bypassing tenant scoping');
