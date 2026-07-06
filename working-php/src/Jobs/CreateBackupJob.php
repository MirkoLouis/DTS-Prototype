<?php

namespace App\Jobs;

class CreateBackupJob
{
    public function handle(): void
    {
        ini_set('memory_limit', '2G');
        set_time_limit(1200);

        // Simple mysqldump wrapper
        $host = '127.0.0.1'; // Ideally from config
        $user = 'root';
        $pass = 'password';
        $dbname = 'dts_prototype';

        $backupDir = BASE_PATH . '/storage/app/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

        $filename = 'backup_' . date('Y_m_d_His') . '.sql';
        $filePath = $backupDir . '/' . $filename;
        $zipPath = $backupDir . '/' . $filename . '.zip';

        // Dump DB
        $command = "mysqldump -h {$host} -u {$user} -p{$pass} {$dbname} > {$filePath}";
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
