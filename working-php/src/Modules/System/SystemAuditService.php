<?php

namespace App\Modules\System;

use App\Common\Config\Config;

/**
 * Append-only audit log for system-level events the database cannot record about itself, such as a
 * backup restore (which replaces the very tables a log row would live in).
 *
 * The log is a file outside the database and outside backups. Each line is JSON:
 * {ts, event, data, prev, mac}. `mac` is an HMAC-SHA256 under APP_INTEGRITY_KEY over the entry,
 * and `prev` is the previous line's mac, so an edited line, a line removed from the middle and
 * reordered lines are all detected by verify(). Lines cut off the end are not: nothing after them
 * records that they existed.
 */
class SystemAuditService
{
    // The same encoding is used to write a line and to compute its mac
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Path of the audit log. The test suite keeps its own file in the .test folder.
     */
    public static function path(): string
    {
        return Config::isTesting() ? Config::testPath('logs/system-audit.log') : BASE_PATH . '/storage/logs/system-audit.log';
    }

    /**
     * Append one event.
     *
     * Never throws: the action being recorded has already happened or is about to, so a failed
     * audit write is reported in the PHP error log instead of failing the caller.
     */
    public static function record(string $event, array $data = []): void
    {
        try {
            $directory = dirname(self::path());
            if (!is_dir($directory)) {
                mkdir($directory, 0770, true);
            }

            // Open for reading and appending without truncating
            $handle = @fopen(self::path(), 'c+');
            if ($handle === false) {
                throw new \RuntimeException('cannot open ' . self::path());
            }

            // One writer at a time, so the web server and the worker chain their entries correctly
            flock($handle, LOCK_EX);
            try {
                $entry = [
                    'ts' => date('c'),
                    'event' => $event,
                    'data' => $data,
                    'prev' => self::lastMac($handle),
                ];
                $entry['mac'] = self::macFor($entry);

                fseek($handle, 0, SEEK_END);
                fwrite($handle, json_encode($entry, self::JSON_FLAGS) . "\n");
                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable $e) {
            error_log("System audit log: could not record {$event}: " . $e->getMessage());
        }
    }

    /**
     * Check every line of the log.
     *
     * @return array{keyed: bool, entries: int, bad_lines: int[]} `keyed` is false when APP_INTEGRITY_KEY is not
     *         set (the log then only detects accidental damage); `bad_lines` holds 1-based line numbers
     */
    public static function verify(): array
    {
        $result = ['keyed' => self::key() !== '', 'entries' => 0, 'bad_lines' => []];
        if (!is_file(self::path())) {
            return $result;
        }

        $previousMac = '';
        $lines = file(self::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $index => $line) {
            // A line is good when it is an entry, links to the line before it, and its mac matches its contents
            $entry = json_decode($line, true);
            $intact = is_array($entry)
                && isset($entry['mac'])
                && ($entry['prev'] ?? null) === $previousMac
                && hash_equals(self::macFor($entry), (string) $entry['mac']);
            if (!$intact) {
                $result['bad_lines'][] = $index + 1;
            }
            $previousMac = is_array($entry) ? (string) ($entry['mac'] ?? '') : '';
        }
        $result['entries'] = count($lines);

        return $result;
    }

    /**
     * The mac of an entry: keyed with APP_INTEGRITY_KEY, or a plain SHA-256 when no key is set.
     */
    private static function macFor(array $entry): string
    {
        unset($entry['mac']);
        $payload = json_encode($entry, self::JSON_FLAGS);
        $key = self::key();

        return $key !== '' ? hash_hmac('sha256', $payload, $key) : hash('sha256', $payload);
    }

    /**
     * The raw integrity key, or an empty string when none (or an invalid one) is configured.
     */
    private static function key(): string
    {
        $decoded = base64_decode((string) Config::get('app', 'integrity_key', ''), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Mac of the last line in the locked file, or an empty string for an empty file.
     *
     * @param resource $handle
     */
    private static function lastMac($handle): string
    {
        $size = fstat($handle)['size'] ?? 0;
        if ($size === 0) {
            return '';
        }

        // Entries are short, so the last complete line is within the final 64 KB
        $chunk = min($size, 65536);
        fseek($handle, -$chunk, SEEK_END);
        $lines = array_values(array_filter(explode("\n", (string) fread($handle, $chunk)), 'strlen'));
        $last = json_decode((string) end($lines), true);

        return is_array($last) ? (string) ($last['mac'] ?? '') : '';
    }
}
