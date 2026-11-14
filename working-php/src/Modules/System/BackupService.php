<?php

namespace App\Modules\System;

use App\Common\Config\Config;

/**
 * Rules for database backups: where they are stored, what they are named, which tables they leave
 * out and when they expire. Shared by the backup and restore jobs and the backups page.
 */
class BackupService
{
    // Queue, cache and scheduler state belongs to the running server, not to a backup: restoring it would
    // re-run old jobs, serve stale cache entries, or replace the row of the restore job that is running
    public const EXCLUDED_TABLES = ['jobs', 'cache', 'cache_locks', 'worker_schedule'];

    private const SECONDS_PER_DAY = 86400;

    /**
     * The folder backups are stored in, created when missing.
     *
     * The test suite uses its own folder, so a test can never delete or overwrite a real backup.
     */
    public static function directory(): string
    {
        $directory = Config::isTesting() ? Config::testPath('backups') : BASE_PATH . '/storage/app/backups';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory;
    }

    /**
     * Name of the SQL file inside a backup: database name, time taken, and number of documents.
     */
    public static function fileName(string $database, int $documentCount, \DateTimeInterface $at): string
    {
        return $database . '_' . $at->format('Y_m_d_His') . '_' . $documentCount . '.sql';
    }

    /**
     * Zip a dump file into a backup archive.
     *
     * Every step is checked: a zip that could not be written completely (for example on a full disk)
     * is removed, so nothing that looks like a backup is left behind.
     *
     * @throws \RuntimeException when the archive cannot be created or completed
     */
    public static function archive(string $sqlPath, string $zipPath): void
    {
        $zip = new \ZipArchive();
        $written = is_file($sqlPath)
            && $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true
            && $zip->addFile($sqlPath, basename($sqlPath))
            && $zip->close();

        // Success means the zip exists and holds the dump
        if ($written && is_file($zipPath) && filesize($zipPath) > 0) {
            return;
        }

        @unlink($zipPath);
        throw new \RuntimeException('Could not write the backup archive ' . basename($zipPath) . '.');
    }

    /**
     * Delete backups older than the age limit, always keeping the newest one.
     *
     * @param int $keepDays days a backup is kept; zero or less turns expiry off
     * @return string[] names of the deleted files
     */
    public static function expire(string $directory, int $keepDays, int $now): array
    {
        // Expiry is off, or there is nothing to expire
        $files = glob($directory . '/*.zip') ?: [];
        if ($keepDays <= 0 || $files === []) {
            return [];
        }

        // Newest first; the newest backup is never deleted, so at least one always exists
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        array_shift($files);

        $cutoff = $now - $keepDays * self::SECONDS_PER_DAY;
        $deleted = [];
        foreach ($files as $file) {
            // Only backups past the limit go, and only those that were actually removed are reported
            if (filemtime($file) < $cutoff && unlink($file)) {
                $deleted[] = basename($file);
            }
        }

        return $deleted;
    }
}
