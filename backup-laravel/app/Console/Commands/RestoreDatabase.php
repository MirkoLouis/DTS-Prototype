<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class RestoreDatabase extends Command
{
    protected $signature = 'db:restore {--file=}';
    protected $description = 'Restores the database from a specific backup file.';

    public function handle()
    {
        $fileName = $this->option('file');

        if (!$fileName) {
            $this->error('The --file option is required.');
            return 1;
        }

        $this->info("Starting database restore from {$fileName}...");

        try {
            // 1. Find the backup file
            $diskName = config('backup.backup.destination.disks')[0];
            $appName = config('backup.backup.name');
            $filePath = $appName . '/' . $fileName;

            if (!Storage::disk($diskName)->exists($filePath)) {
                $this->error("Backup file not found at path: {$filePath} on disk '{$diskName}'.");
                return 1;
            }
            $this->info("Found backup file on disk '{$diskName}'.");

            // 2. Create a temporary directory for extraction
            $tempDir = storage_path('app/backup-temp/restore-' . time());
            File::makeDirectory($tempDir, 0755, true, true);
            $this->info("Created temporary directory at {$tempDir}.");

            // 3. Copy backup to temp dir and extract
            $backupContents = Storage::disk($diskName)->get($filePath);
            $tempZipPath = $tempDir . '/' . $fileName;
            File::put($tempZipPath, $backupContents);

            $zip = new ZipArchive;
            if ($zip->open($tempZipPath) === TRUE) {
                $zip->extractTo($tempDir);
                $zip->close();
                $this->info("Successfully extracted backup archive.");
            } else {
                throw new \Exception('Failed to open the backup zip archive.');
            }

            // 4. Find the .sql dump file
            $sqlFile = collect(File::allFiles($tempDir . '/db-dumps'))->first(function ($file) {
                return pathinfo($file, PATHINFO_EXTENSION) === 'sql';
            });

            if (!$sqlFile) {
                throw new \Exception('No .sql file found in the backup archive.');
            }
            $this->info("Found SQL dump file: " . $sqlFile->getFilename());

            // 5. Put app in maintenance mode (optional but recommended)
            $this->call('down');

            // 6. Restore the database (MySQL specific)
            $this->info('Disabling foreign key checks and dropping all tables...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            $tables = DB::select('SHOW TABLES');
            $dbName = DB::getDatabaseName();
            $droplist = [];
            foreach ($tables as $table) {
                $droplist[] = $table->{"Tables_in_{$dbName}"};
            }
            if (!empty($droplist)) {
                DB::statement('DROP TABLE ' . implode(',', $droplist));
                $this->info('Dropped ' . count($droplist) . ' tables.');
            }
            
            $this->info('Executing SQL dump...');
            DB::unprepared(File::get($sqlFile->getPathname()));
            
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->info('Database restore complete.');

            // 7. Bring app out of maintenance mode
            $this->call('up');
            
            // 8. Clean up temporary files
            File::deleteDirectory($tempDir);
            $this->info('Cleaned up temporary files.');

            $this->info('Database restore process finished successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->error('An error occurred during the restore process: ' . $e->getMessage());
            // Clean up if temp dir was created
            if (isset($tempDir) && File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
            // Ensure we are not stuck in maintenance mode
            if (app()->isDownForMaintenance()) {
                $this->call('up');
            }
            return 1;
        }
    }
}