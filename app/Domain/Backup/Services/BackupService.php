<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Models\BackupLog;
use App\Domain\Backup\Models\BackupSetting;
use App\Domain\Shared\Support\TenantModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    public function __construct(private readonly GoogleDriveService $drive) {}

    /**
     * $companyId: which company's data the dump is filtered to. Null means
     * an unfiltered, whole-database dump (today's original behaviour,
     * including every other company's data) — only ever reachable via the
     * is_super_admin gate in BackupSettingController, never from the
     * per-company scheduled check.
     */
    public function run(string $trigger, ?int $companyId): BackupLog
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
            $this->dumpDatabase($sqlPath, $companyId);
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

    private function dumpDatabase(string $outputPath, ?int $companyId): void
    {
        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");
        $env = ['MYSQL_PWD' => $config['password']];
        $base = [
            config('backup.mysqldump_path'),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
        ];

        if ($companyId === null) {
            $result = Process::timeout(300)->env($env)->run([
                ...$base,
                '--result-file='.$outputPath,
                $config['database'],
            ]);

            if ($result->failed()) {
                throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
            }
        } else {
            // Schema for every table (structure only, no rows — nothing
            // tenant-specific to filter), then data for only the tenant
            // tables, filtered to this one company, appended after.
            $schema = Process::timeout(300)->env($env)->run([
                ...$base,
                '--no-data',
                '--result-file='.$outputPath,
                $config['database'],
            ]);

            if ($schema->failed()) {
                throw new RuntimeException('mysqldump (schema) failed: '.$schema->errorOutput());
            }

            $dataPath = $outputPath.'.data';
            $data = Process::timeout(300)->env($env)->run([
                ...$base,
                '--no-create-info',
                '--where=company_id='.$companyId,
                '--result-file='.$dataPath,
                $config['database'],
                ...TenantModels::tables(),
            ]);

            if ($data->failed()) {
                @unlink($dataPath);
                throw new RuntimeException('mysqldump (data) failed: '.$data->errorOutput());
            }

            file_put_contents($outputPath, file_get_contents($dataPath), FILE_APPEND);
            @unlink($dataPath);
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
