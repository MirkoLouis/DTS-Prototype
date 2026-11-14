<?php

namespace App\Modules\System\Jobs;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Database\MysqlCli;
use App\Modules\System\BackupService;

class CreateBackupJob
{
    /**
     * Dump the database into a zip in the backup folder, then expire backups past the age limit.
     *
     * @return string file name of the backup zip
     * @throws \RuntimeException when the dump fails; no backup file is left behind
     */
    public function handle(): string
    {
        ini_set('memory_limit', '2G');
        set_time_limit(1200);

        $database = (string) Config::get('database', 'dbname');
        $backupDir = BackupService::directory();
        $docCount = (int) (Database::getInstance()->query("SELECT COUNT(*) as count FROM documents")->fetch()['count'] ?? 0);

        $filename = BackupService::fileName($database, $docCount, new \DateTimeImmutable());
        $filePath = $backupDir . '/' . $filename;
        $zipPath = $filePath . '.zip';

        // A failed dump or a failed archive leaves no file that could be mistaken for a backup
        try {
            MysqlCli::run('mysqldump', $this->dumpArguments($database), null, $filePath);
            BackupService::archive($filePath, $zipPath);
        } finally {
            // The plain SQL file is only an intermediate step, whether or not the backup succeeded
            @unlink($filePath);
        }

        // Remove backups past the age limit; the newest one always stays
        BackupService::expire($backupDir, (int) Config::get('app', 'backup_keep_days', 14), time());

        return $filename . '.zip';
    }

    /**
     * mysqldump arguments: one consistent snapshot, without the tables that belong to the running server.
     * Tests override this to make the dump fail.
     *
     * @return string[]
     */
    protected function dumpArguments(string $database): array
    {
        $arguments = ['--single-transaction', '--quick'];
        foreach (BackupService::EXCLUDED_TABLES as $table) {
            $arguments[] = "--ignore-table={$database}.{$table}";
        }

        return $arguments;
    }
}
