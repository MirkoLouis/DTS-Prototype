<?php

namespace App\Modules\Users;

use PDO;

/**
 * Finds or creates a department by name for the legacy import.
 *
 * departments.name is UNIQUE under a case-insensitive collation, so a name that differs from an existing one only in
 * letter case (or accents) cannot be inserted: it is merged into the existing department, and the merge is logged so
 * the operator sees which spellings were folded together.
 */
class DepartmentResolver
{
    /**
     * Id of the department called $name, creating it when no department matches.
     *
     * @param array         $cache id by name as spelled in the import, filled by this method
     * @param callable|null $log   receives a message when a name is merged into an existing department (default: error_log)
     */
    public static function getOrCreate(PDO $connection, string $name, array &$cache, ?callable $log = null): int
    {
        // A blank name stands for an unknown department; a name seen before needs no query
        if ($name === '') {
            $name = 'Unknown Department';
        }
        if (isset($cache[$name])) {
            return (int) $cache[$name];
        }

        // An insert that hits the UNIQUE index reuses the existing row's id (and changes nothing, so rowCount is 0)
        $insert = $connection->prepare('INSERT INTO departments (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        $insert->execute([$name]);
        $id = (int) $connection->lastInsertId();
        $cache[$name] = $id;

        // Only a merge needs reporting
        if ($insert->rowCount() === 0) {
            $existing = $connection->prepare('SELECT name FROM departments WHERE id = ?');
            $existing->execute([$id]);
            $message = "Legacy import: department '{$name}' merged into existing department '" . $existing->fetchColumn() . "' (id {$id}).";
            ($log ?? 'error_log')($message);
        }

        return $id;
    }
}
