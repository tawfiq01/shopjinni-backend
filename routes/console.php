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
