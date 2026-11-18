<?php

namespace App\Common\Database;

use PDO;

/**
 * Runs a SQL script (schema file or migration) statement by statement on an existing connection.
 *
 * Scripts must not contain semicolons inside string literals. CREATE DATABASE and USE lines are skipped,
 * so a script can only touch the database the connection already points at.
 *
 * A statement that returns rows is a message from the script when its result has a column named NOTICE_COLUMN
 * (the migration uses this for "NOT APPLIED: ..."). The runner collects those messages and returns them, so a
 * caller that has no terminal (the backup restore) can still show them. Any other result is discarded.
 */
class SqlScriptRunner
{
    // Column name that marks a result row as a message for the operator
    public const NOTICE_COLUMN = 'migration_notice';

    /**
     * Execute every statement of the script at $path and return the notices it raised, one text per result row.
     *
     * @return string[]
     */
    public static function runFile(PDO $connection, string $path): array
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read SQL script: {$path}");
        }

        // Drop comment lines and the CREATE DATABASE / USE lines
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, '--') || stripos($trim, 'CREATE DATABASE') === 0 || stripos($trim, 'USE ') === 0) {
                continue;
            }
            $lines[] = $line;
        }

        // Execute statement by statement, skipping the empty tail after the last semicolon
        $notices = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) === '') {
                continue;
            }

            // query() (not exec()) so a statement that returns rows does not block the next one; a failing statement throws
            $result = $connection->query($statement);
            foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // Rows without the notice column are ordinary results and are dropped
                if (array_key_exists(self::NOTICE_COLUMN, $row)) {
                    $notices[] = self::noticeText($row);
                }
            }
            $result->closeCursor();
        }

        return $notices;
    }

    /**
     * One text for a notice row: the notice, followed by the other columns as name=value.
     */
    private static function noticeText(array $row): string
    {
        $text = (string) $row[self::NOTICE_COLUMN];
        unset($row[self::NOTICE_COLUMN]);

        // Details such as the duplicated department name
        $details = [];
        foreach ($row as $column => $value) {
            $details[] = $column . '=' . $value;
        }

        return $details === [] ? $text : $text . ' [' . implode(', ', $details) . ']';
    }
}
