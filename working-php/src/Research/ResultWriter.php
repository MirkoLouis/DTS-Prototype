<?php

namespace App\Research;

use App\Modules\Integrity\HashChainService;

/**
 * Appends result rows to CSV files inside the current run folder (storage/test-results/<run_id>/).
 */
class ResultWriter
{
    /**
     * The run folder: DTS_RESULTS_DIR when set, otherwise a new folder created (and exported) now.
     * DTS_RESULTS_DIR is set internally once per run; pointing it by hand at an earlier run folder overwrites that run's files.
     */
    public static function dir(): string
    {
        // An inherited folder (PHPUnit parent, forked child, subprocess) is reused once it is confirmed to sit under storage/test-results
        $root = BASE_PATH . '/storage/test-results';
        $env = getenv('DTS_RESULTS_DIR');
        if ($env !== false && $env !== '') {
            // The run root may not exist on a first run; it is always safe to create
            if (!is_dir($root)) {
                mkdir($root, 0775, true);
            }
            $parent = realpath(dirname($env));
            $realRoot = realpath($root);
            if ($parent === false || $realRoot === false || ($parent !== $realRoot && !str_starts_with($parent, $realRoot . '/'))) {
                throw new \InvalidArgumentException('DTS_RESULTS_DIR must be inside ' . $root . ': ' . $env);
            }

            // Create the folder only after the containment check passed
            if (!is_dir($env)) {
                mkdir($env, 0775, true);
            }

            return $env;
        }

        // Otherwise create this process's own folder and export it for children
        $runId = getenv('DTS_RUN_ID');
        if ($runId === false || $runId === '') {
            $runId = self::now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2));
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $runId) || $runId === '.' || $runId === '..') {
            throw new \InvalidArgumentException('Invalid DTS_RUN_ID: ' . $runId);
        }
        $dir = $root . '/' . $runId;

        // Never overwrite an earlier run's results
        if (is_file($dir . '/run.json')) {
            throw new \RuntimeException("Run folder {$dir} already holds results (run.json); choose a different DTS_RUN_ID.");
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        putenv('DTS_RESULTS_DIR=' . $dir);
        $_ENV['DTS_RESULTS_DIR'] = $dir;
        putenv('DTS_RUN_ID=' . $runId);
        $_ENV['DTS_RUN_ID'] = $runId;

        // Point `latest` at the new folder (skipped for throwaway runs)
        if (getenv('DTS_NO_LATEST') === false || getenv('DTS_NO_LATEST') === '') {
            self::pointLatestAt($root, $runId);
        }

        return $dir;
    }

    /**
     * The current time in the app timezone (Asia/Manila), whatever the PHP default timezone is.
     */
    private static function now(): \DateTimeImmutable
    {
        // Explicit zone keeps run ids and run.json stamps ordered across PHPUnit and CLI scripts
        return new \DateTimeImmutable('now', new \DateTimeZone(HashChainService::APP_TIMEZONE));
    }

    /**
     * Replace the `latest` symlink atomically: build a temporary link, then rename it over `latest`.
     */
    private static function pointLatestAt(string $root, string $name): void
    {
        // A leftover plain directory called latest is never overwritten
        $latest = $root . '/latest';
        if (file_exists($latest) && !is_link($latest)) {
            return;
        }
        $tmp = $root . '/.latest-' . getmypid() . '-' . bin2hex(random_bytes(2));
        if (@symlink($name, $tmp)) {
            if (!@rename($tmp, $latest)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Write run.json for a script or test run (into $dir, default the run folder); $extra is merged over the standard fields.
     */
    public static function writeRunJson(array $extra = [], ?string $dir = null): void
    {
        // Standard fields describe the run and the machine
        $dir ??= self::dir();
        $commit = trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(BASE_PATH)) . ' describe --always --dirty 2>/dev/null')) ?: 'unknown';
        $data = array_merge([
            'run_id' => basename($dir),
            'started_at' => self::now()->format('c'),
            'finished_at' => self::now()->format('c'),
            'arguments' => $_SERVER['argv'] ?? [],
            'git_describe' => $commit,
            'php_version' => PHP_VERSION,
            'cpu' => self::cpuModel(),
            'ram_gb' => self::ramGb(),
        ], $extra);

        // The file list is taken last so it includes everything written so far
        $files = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        $data['files'] = array_values(array_unique(array_merge($files, ['run.json'])));
        file_put_contents($dir . '/run.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * CPU model from /proc/cpuinfo (host values), or the machine architecture.
     */
    public static function cpuModel(): string
    {
        // First "model name" line
        foreach (@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'model name')) {
                return trim(explode(':', $line, 2)[1]);
            }
        }

        return php_uname('m');
    }

    /**
     * Installed RAM in GB from /proc/meminfo, one decimal.
     */
    public static function ramGb(): float
    {
        // MemTotal is reported in kB
        foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'MemTotal:')) {
                return round(((int) preg_replace('/\D/', '', $line)) / 1048576, 1);
            }
        }

        return 0.0;
    }

    /**
     * Append one row; the header (the row's keys) is written when the file is created.
     */
    public static function append(string $file, array $row): void
    {
        $path = self::dir() . '/' . basename($file);
        $isNew = !file_exists($path) || filesize($path) === 0;

        // Lock so concurrent workers never interleave a row
        $handle = fopen($path, 'a');
        flock($handle, LOCK_EX);
        if ($isNew) {
            fputcsv($handle, array_keys($row), ',', '"', '\\');
        }
        fputcsv($handle, array_values($row), ',', '"', '\\');
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
