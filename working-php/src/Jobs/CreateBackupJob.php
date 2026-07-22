<?php

namespace App\Jobs;

class CreateBackupJob
{
    public function handle(): void
    {
        ini_set('memory_limit', '2G');
        set_time_limit(1200);

        // Simple mysqldump wrapper
        $config = require BASE_PATH . '/src/Config/config.php';
        $dbConfig = $config['database'];
        $host = escapeshellarg($dbConfig['host']);
        $user = escapeshellarg($dbConfig['user']);
        $pass = escapeshellarg($dbConfig['password']);
        $dbname = escapeshellarg($dbConfig['dbname']);

        $backupDir = BASE_PATH . '/storage/app/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

        $filename = 'backup_' . date('Y_m_d_His') . '.sql';
        $filePath = $backupDir . '/' . $filename;
        $zipPath = $backupDir . '/' . $filename . '.zip';

        // Dump DB
        $passFlag = $dbConfig['password'] !== '' ? "-p{$pass}" : "";
        $command = "mysqldump -h {$host} -u {$user} {$passFlag} {$dbname} > " . escapeshellarg($filePath);
        exec($command);

        // Zip it
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
            $zip->addFile($filePath, $filename);
            $zip->close();
        }

        // Cleanup sql file
        if (file_exists($filePath)) unlink($filePath);
    }
}
