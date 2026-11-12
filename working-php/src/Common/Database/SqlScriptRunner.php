<?php

namespace App\Common\Database;

use PDO;

/**
 * Runs a SQL script (schema file or migration) statement by statement on an existing connection.
 *
 * Scripts must not contain semicolons inside string literals. CREATE DATABASE and USE lines are skipped,
 * so a script can only touch the database the connection already points at.
 */
class SqlScriptRunner
{
    /**
     * Execute every statement of the script at $path.
     */
    public static function runFile(PDO $connection, string $path): void
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
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $connection->exec($statement);
            }
        }
    }
}
