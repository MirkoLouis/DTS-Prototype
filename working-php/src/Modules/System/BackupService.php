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

    // Time limit of a backup or restore job, for set_time_limit(); it does not count time spent in the dump and
    // mysql child processes, so it says nothing about whether a working folder is still in use
    public const JOB_TIME_LIMIT_SECONDS = 1200;

    // A new working folder is not judged abandoned for this long: a job needs a moment between creating the
    // folder and locking it
    public const WORKING_DIRECTORY_GRACE_SECONDS = 60;

    // Lock handles of the working folders this process created, keyed by folder path; the operating system
    // releases each lock when the process ends, however it ends
    private static array $workingDirectoryLocks = [];

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
     * The folder that holds the working folders of running jobs, created when missing.
     */
    public static function temporaryRoot(): string
    {
        $directory = Config::isTesting() ? Config::testPath('temp') : BASE_PATH . '/storage/temp';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory;
    }

    /**
     * Create a new, uniquely named folder for one job's intermediate files and return its path.
     * Only the owner can read it, because a dump holds password hashes and contact details.
     *
     * The folder is locked until the job removes it or its process ends, so another job can tell a
     * running job's folder from a leftover.
     */
    public static function workingDirectory(): string
    {
        $directory = self::temporaryRoot() . '/' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        // Hold an exclusive lock on the lock file for as long as the job runs
        $handle = fopen($directory . '/.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Could not lock the working folder ' . basename($directory) . '.');
        }
        self::$workingDirectoryLocks[$directory] = $handle;

        return $directory;
    }

    /**
     * Delete a working folder and everything in it.
     *
     * Does nothing unless the folder is directly inside the temporary root, so a wrong path can never delete anything else.
     */
    public static function removeWorkingDirectory(string $directory): void
    {
        // Resolve the path and refuse anything that is not a direct child of the temporary root
        $real = realpath($directory);
        $root = realpath(self::temporaryRoot());
        if ($real === false || $root === false || dirname($real) !== $root) {
            return;
        }

        // Release the lock this process holds on the folder
        foreach ([$directory, $real] as $key) {
            if (isset(self::$workingDirectoryLocks[$key])) {
                flock(self::$workingDirectoryLocks[$key], LOCK_UN);
                fclose(self::$workingDirectoryLocks[$key]);
                unset(self::$workingDirectoryLocks[$key]);
            }
        }

        self::deleteTree($real);
    }

    /**
     * Delete a folder with everything in it, dot-files and nested folders included.
     * A symbolic link is unlinked, never followed.
     */
    private static function deleteTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            // Skip the folder itself and its parent
            if ($name === '.' || $name === '..') {
                continue;
            }

            // Descend into real folders only; links and files are unlinked
            $child = $path . '/' . $name;
            if (is_dir($child) && !is_link($child)) {
                self::deleteTree($child);
                continue;
            }
            @unlink($child);
        }
        @rmdir($path);
    }

    /**
     * Whether no process holds the lock of a working folder. A folder without a lock file is not held.
     */
    private static function isUnheld(string $folder): bool
    {
        $handle = @fopen($folder . '/.lock', 'r');
        if ($handle === false) {
            return true;
        }

        // Release the probe lock at once, so the folder can be removed afterwards
        $unheld = flock($handle, LOCK_EX | LOCK_NB);
        if ($unheld) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $unheld;
    }

    /**
     * Remove working folders left behind by a job that was killed before it could clean up.
     *
     * A folder is abandoned when no running job holds its lock and it is older than the grace period.
     *
     * @return string[] names of the removed folders
     */
    public static function clearAbandonedWorkingDirectories(int $now): array
    {
        $removed = [];
        foreach (glob(self::temporaryRoot() . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
            // A recent folder may belong to a job that has not locked it yet, and a locked one is in use
            if (filemtime($folder) >= $now - self::WORKING_DIRECTORY_GRACE_SECONDS || !self::isUnheld($folder)) {
                continue;
            }
            self::removeWorkingDirectory($folder);
            if (!is_dir($folder)) {
                $removed[] = basename($folder);
                error_log('Removed abandoned backup working folder ' . basename($folder) . '.');
            }
        }

        return $removed;
    }

    /**
     * Name of the SQL file inside a backup: database name, an optional label (such as "pre-restore"),
     * time taken, and number of documents.
     */
    public static function fileName(string $database, int $documentCount, \DateTimeInterface $at, string $label = ''): string
    {
        // Labels are fixed words chosen by the code; anything else is reduced to safe file-name characters
        $label = preg_replace('/[^A-Za-z0-9-]/', '', $label);

        return $database . '_' . ($label !== '' ? $label . '_' : '') . $at->format('Y_m_d_His') . '_' . $documentCount . '.sql';
    }

    /**
     * The warning to show the admin when backups are not encrypted, or null when a password is set.
     */
    public static function encryptionWarning(string $password): ?string
    {
        if ($password !== '') {
            return null;
        }

        return 'Backups are stored unencrypted. Set BACKUP_PASSWORD in .env to encrypt new backups, and keep a copy of it away from this server.';
    }

    /**
     * Zip a dump file into a backup archive, encrypted with AES-256 when a password is given.
     *
     * A dump holds password hashes, wrapped signing keys and clients' contact details, so an archive
     * that leaves the server should be unreadable without the password. Every step is checked: a zip
     * that could not be written completely (for example on a full disk) is removed, so nothing that
     * looks like a backup is left behind.
     *
     * @throws \RuntimeException when the archive cannot be created or completed
     */
    public static function archive(string $sqlPath, string $zipPath, string $password = ''): void
    {
        $entry = basename($sqlPath);
        $zip = new \ZipArchive();
        $written = is_file($sqlPath)
            && $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true
            && $zip->addFile($sqlPath, $entry)
            && ($password === '' || $zip->setEncryptionName($entry, \ZipArchive::EM_AES_256, $password))
            && $zip->close();

        // Success means the zip exists and holds the dump
        if ($written && is_file($zipPath) && filesize($zipPath) > 0) {
            return;
        }

        @unlink($zipPath);
        throw new \RuntimeException('Could not write the backup archive ' . basename($zipPath) . '.');
    }

    /**
     * Extract the dump from a backup archive into a folder and return its path.
     *
     * Only an archive holding exactly one file with a plain name ending in .sql is accepted, so a
     * crafted archive can never write outside the folder or drop another kind of file into it.
     * An archive made before encryption was turned on is read without the password.
     *
     * @throws \RuntimeException when the archive is not a backup, or cannot be read with this password
     */
    public static function extract(string $zipPath, string $directory, string $password = ''): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open the backup archive ' . basename($zipPath) . '.');
        }

        try {
            // Exactly one entry, a bare file name, ending in .sql
            $entry = $zip->numFiles === 1 ? (string) $zip->getNameIndex(0) : '';
            if ($entry === '' || basename($entry) !== $entry || !str_ends_with($entry, '.sql')) {
                throw new \RuntimeException('The backup archive ' . basename($zipPath) . ' has unexpected contents.');
            }

            if ($password !== '') {
                $zip->setPassword($password);
            }

            // A wrong password can leave an empty or partial file, which must not stay
            $target = $directory . '/' . $entry;
            if (!@$zip->extractTo($directory, $entry) || !is_file($target)) {
                @unlink($target);
                throw new \RuntimeException('Could not extract the backup. Is BACKUP_PASSWORD the one it was created with?');
            }

            return $target;
        } finally {
            $zip->close();
        }
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
