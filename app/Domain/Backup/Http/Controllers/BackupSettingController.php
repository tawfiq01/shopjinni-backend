<?php

namespace App\Domain\Backup\Http\Controllers;

use App\Domain\Backup\Models\BackupLog;
use App\Domain\Backup\Models\BackupSetting;
use App\Domain\Backup\Services\BackupService;
use App\Domain\Backup\Services\GoogleDriveService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use RuntimeException;

class BackupSettingController extends Controller
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly BackupService $backup,
    ) {}

    public function status()
    {
        $setting = BackupSetting::current();

        return response()->json([
            'google_connected' => $setting->isGoogleConnected(),
            'google_account_email' => $setting->google_account_email,
            'drive_folder_id' => $setting->drive_folder_id,
            'drive_folder_name' => $setting->drive_folder_name,
            'backup_time' => $setting->backup_time,
            'is_enabled' => $setting->is_enabled,
            'last_backup_at' => $setting->last_backup_at?->toIso8601String(),
            'last_backup_status' => $setting->last_backup_status,
            'last_backup_message' => $setting->last_backup_message,
        ]);
    }

    public function googleAuthUrl()
    {
        return response()->json(['url' => $this->drive->getAuthUrl()]);
    }

    public function disconnectGoogle()
    {
        $this->drive->disconnect();

        return response()->json(['message' => 'Disconnected from Google Drive.']);
    }

    public function driveFolders()
    {
        try {
            return response()->json(['data' => $this->drive->listFolders()]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'drive_folder_id' => ['nullable', 'string'],
            'drive_folder_name' => ['nullable', 'string'],
            'backup_time' => ['nullable', 'date_format:H:i'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        if ($data['is_enabled'] && empty($data['backup_time'])) {
            return response()->json([
                'message' => 'Pick a daily backup time before enabling automatic backups.',
            ], 422);
        }

        $setting = BackupSetting::current();
        $setting->update($data);

        return response()->json(['message' => 'Backup settings saved.']);
    }

    public function runNow()
    {
        $setting = BackupSetting::current();

        if (! $setting->isGoogleConnected()) {
            return response()->json(['message' => 'Connect Google Drive before running a backup.'], 422);
        }

        $log = $this->backup->run('manual');

        if ($log->status === 'failed') {
            return response()->json(['message' => $log->message], 422);
        }

        return response()->json([
            'message' => "Backup completed — {$log->file_name}.",
            'file_size_bytes' => $log->file_size_bytes,
        ]);
    }

    public function logs()
    {
        return response()->json([
            'data' => BackupLog::orderByDesc('id')->limit(50)->get(),
        ]);
    }
}
