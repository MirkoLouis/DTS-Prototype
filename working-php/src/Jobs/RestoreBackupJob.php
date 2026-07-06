<?php

namespace App\Jobs;

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

        $backupDir = BASE_PATH . '/storage/app/backups';
        $zipPath = $backupDir . '/' . $this->fileName;

        if (!file_exists($zipPath)) {
            return;
        }

        $host = '127.0.0.1';
        $user = 'root';
        $pass = 'password';
        $dbname = 'dts_prototype';

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
            
            // Restore DB
            $command = "mysql -h {$host} -u {$user} -p{$pass} {$dbname} < {$filePath}";
            exec($command);
            
            // Cleanup extracted SQL file
            unlink($filePath);
        }
    }
}
