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

        $config = require BASE_PATH . '/src/Config/config.php';
        $dbConfig = $config['database'];
        $host = escapeshellarg($dbConfig['host']);
        $user = escapeshellarg($dbConfig['user']);
        $pass = escapeshellarg($dbConfig['password']);
        $dbname = escapeshellarg($dbConfig['dbname']);

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
            $passFlag = $dbConfig['password'] !== '' ? "-p{$pass}" : "";
            $command = "mysql -h {$host} -u {$user} {$passFlag} {$dbname} < " . escapeshellarg($filePath);
            exec($command);
            
            // Cleanup extracted SQL file
            unlink($filePath);
        }
    }
}
