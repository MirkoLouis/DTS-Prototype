<?php

namespace App\Modules\System\Jobs;

use App\Common\Config\Config;
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

        // Take the dump out of the archive, feed it to the mysql client, and never leave the plain SQL file behind
        $filePath = BackupService::extract($zipPath, $backupDir, (string) Config::get('app', 'backup_password', ''));
        try {
            MysqlCli::run('mysql', [], $filePath);
        } finally {
            @unlink($filePath);
        }

        // A backup taken before a schema change restores the old tables; bring them back up to date
        SqlScriptRunner::runFile(Database::getInstance()->getConnection(), BASE_PATH . '/scripts/migrations/master-migration.sql');
    }
}
