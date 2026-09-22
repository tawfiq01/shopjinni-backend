<?php

namespace App\Domain\Backup\Console\Commands;

use App\Domain\Backup\Models\BackupSetting;
use App\Domain\Backup\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Runs every minute (see routes/console.php) and fires the actual backup
 * once, on the day, at the admin-configured time — since the time is
 * user-configurable at runtime it can't be a fixed ->dailyAt() schedule
 * entry, so this checks the clock itself instead.
 */
class RunScheduledBackupCheck extends Command
{
    protected $signature = 'backup:check-schedule';

    protected $description = 'Run the daily database backup if the configured time has arrived';

    public function handle(BackupService $backup): int
    {
        $setting = BackupSetting::current();

        if (! $setting->is_enabled || ! $setting->isGoogleConnected() || $setting->backup_time === null) {
            return self::SUCCESS;
        }

        $now = now();

        if ($setting->last_scheduled_run_date?->isSameDay($now)) {
            return self::SUCCESS; // already ran today
        }

        if ($now->format('H:i') !== $setting->backup_time) {
            return self::SUCCESS; // not time yet
        }

        $setting->update(['last_scheduled_run_date' => $now->toDateString()]);

        $log = $backup->run('scheduled');
        $this->info("Scheduled backup {$log->status}.");

        return self::SUCCESS;
    }
}
