<?php

namespace App\Modules\System\Jobs;

use App\Common\Database\Database;
use App\Common\Database\MysqlCli;
use App\Common\Database\SqlScriptRunner;
use App\Modules\System\BackupService;

class RestoreBackupJob
{
    protected $fileName;

    public function __construct(string $fileName)
    {
        $this->fileName = $fileName;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '2G');
        set_time_limit(1200);

        $backupDir = BackupService::directory();
        $zipPath = $backupDir . '/' . $this->fileName;

        if (!file_exists($zipPath)) {
            return;
        }

        // Extract
        $zip = new \ZipArchive();
        $sqlFile = null;
        if ($zip->open($zipPath) === TRUE) {
            $sqlFile = $zip->getNameIndex(0);
            $zip->extractTo($backupDir);
            $zip->close();
        }

        if ($sqlFile && file_exists($backupDir . '/' . $sqlFile)) {
            $filePath = $backupDir . '/' . $sqlFile;

            // Feed the dump to the mysql client; the extracted file is removed whether or not it succeeds
            try {
                MysqlCli::run('mysql', [], $filePath);
            } finally {
                @unlink($filePath);
            }

            // A backup taken before a schema change restores the old tables; bring them back up to date
            SqlScriptRunner::runFile(Database::getInstance()->getConnection(), BASE_PATH . '/scripts/migrations/master-migration.sql');
        }
    }
}
