<?php

namespace App\Domain\Backup\Console\Commands;

use App\Domain\Backup\Models\BackupSetting;
use App\Domain\Backup\Services\BackupService;
use App\Domain\Companies\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Runs every minute (see routes/console.php) and fires the actual backup
 * once, on the day, at the admin-configured time — since the time is
 * user-configurable at runtime it can't be a fixed ->dailyAt() schedule
 * entry, so this checks the clock itself instead.
 *
 * One row per company now (was a single global row): this deliberately
 * bypasses BackupSetting's company scope to see every company's row (a
 * console context has no authenticated user for the scope to resolve
 * anyway), then processes each one under CurrentCompany::forceFor so its
 * backup is filtered to that company's own data — every automatic backup
 * is inherently shop-scoped, regardless of who configured it.
 */
class RunScheduledBackupCheck extends Command
{
    protected $signature = 'backup:check-schedule';

    protected $description = 'Run the daily database backup for every company whose configured time has arrived';

    public function handle(BackupService $backup): int
    {
        $now = now();

        $settings = BackupSetting::withoutGlobalScope('company')
            ->where('is_enabled', true)
            ->get();

        foreach ($settings as $setting) {
            CurrentCompany::forceFor($setting->company_id, function () use ($setting, $backup, $now) {
                if (! $setting->isGoogleConnected() || $setting->backup_time === null) {
                    return;
                }

                if ($setting->last_scheduled_run_date?->isSameDay($now)) {
                    return; // already ran today
                }

                if ($now->format('H:i') !== $setting->backup_time) {
                    return; // not time yet
                }

                $setting->update(['last_scheduled_run_date' => $now->toDateString()]);

                $log = $backup->run('scheduled', $setting->company_id);
                $this->info("Company {$setting->company_id} scheduled backup {$log->status}.");
            });
        }

        return self::SUCCESS;
    }
}
