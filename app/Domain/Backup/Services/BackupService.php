<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Models\BackupLog;
use App\Domain\Backup\Models\BackupSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    public function __construct(private readonly GoogleDriveService $drive) {}

    public function run(string $trigger = 'manual'): BackupLog
    {
        $setting = BackupSetting::current();
        $stamp = now()->format('Y-m-d_His');
        $tmpDir = storage_path('app/backup-tmp');
        $sqlPath = "{$tmpDir}/mobishop_{$stamp}.sql";
        $zipPath = "{$tmpDir}/mobishop_{$stamp}.zip";

        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        try {
            $this->dumpDatabase($sqlPath);
            $this->zip($sqlPath, $zipPath);

            $uploaded = $this->drive->uploadFile($zipPath, basename($zipPath), $setting->drive_folder_id);

            $log = BackupLog::create([
                'trigger' => $trigger,
                'status' => 'success',
                'file_name' => basename($zipPath),
                'file_size_bytes' => $uploaded['size'],
            ]);

            $setting->update([
                'last_backup_at' => now(),
                'last_backup_status' => 'success',
                'last_backup_message' => null,
            ]);

            return $log;
        } catch (Throwable $e) {
            Log::error('Database backup failed', ['error' => $e->getMessage()]);

            $log = BackupLog::create([
                'trigger' => $trigger,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);

            $setting->update([
                'last_backup_at' => now(),
                'last_backup_status' => 'failed',
                'last_backup_message' => $e->getMessage(),
            ]);

            return $log;
        } finally {
            @unlink($sqlPath);
            @unlink($zipPath);
        }
    }

    private function dumpDatabase(string $outputPath): void
    {
        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");

        $result = Process::timeout(300)
            ->env(['MYSQL_PWD' => $config['password']])
            ->run([
                config('backup.mysqldump_path'),
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--user='.$config['username'],
                '--single-transaction',
                '--result-file='.$outputPath,
                $config['database'],
            ]);

        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('mysqldump produced an empty file.');
        }
    }

    private function zip(string $sqlPath, string $zipPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create the backup zip file.');
        }

        $zip->addFile($sqlPath, basename($sqlPath));
        $zip->close();
    }
}
