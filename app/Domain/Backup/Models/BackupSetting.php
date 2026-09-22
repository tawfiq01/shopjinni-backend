<?php

namespace App\Domain\Backup\Models;

use Illuminate\Database\Eloquent\Model;

class BackupSetting extends Model
{
    protected $fillable = [
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'google_account_email',
        'drive_folder_id',
        'drive_folder_name',
        'backup_time',
        'is_enabled',
        'last_scheduled_run_date',
        'last_backup_at',
        'last_backup_status',
        'last_backup_message',
    ];

    protected function casts(): array
    {
        return [
            'google_access_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
            'is_enabled' => 'boolean',
            'last_scheduled_run_date' => 'date',
            'last_backup_at' => 'datetime',
        ];
    }

    /**
     * There is only ever one row (id=1) — the whole app configures a single
     * Google Drive destination and a single daily backup time.
     */
    public static function current(): self
    {
        // firstOrCreate's create() path leaves is_enabled null in-memory
        // (only the DB column default applies) unless it's set explicitly
        // here — same class of bug as an uncast boolean column elsewhere
        // in this app.
        return static::firstOrCreate(['id' => 1], ['is_enabled' => false]);
    }

    public function isGoogleConnected(): bool
    {
        return ! empty($this->google_refresh_token);
    }
}
