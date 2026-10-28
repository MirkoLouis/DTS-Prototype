<?php

namespace App\Research;

/**
 * Appends result rows to CSV files under storage/test-results/.
 */
class ResultWriter
{
    /**
     * Append one row; the header (the row's keys) is written when the file is created.
     */
    public static function append(string $file, array $row): void
    {
        $dir = BASE_PATH . '/storage/test-results';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . '/' . basename($file);
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
