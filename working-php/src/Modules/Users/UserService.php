<?php

namespace App\Modules\Users;

use App\Common\Database\Database;

/**
 * Account lifecycle rules.
 *
 * Accounts are deactivated, never deleted: their signed log entries and key history must stay
 * attributable and verifiable. AuthMiddleware refuses a deactivated account on its next request.
 */
class UserService
{
    // Roles an account can have
    public const ROLES = ['staff', 'officer', 'admin'];

    // Department every Records Officer belongs to
    public const RECORDS_UNIT = 'Records Unit';

    /**
     * Department an account of the given role is saved with.
     *
     * Staff and Records Officers need a department, because the workflow routes documents to
     * departments; a Records Officer belongs to the Records Unit; an admin has none.
     *
     * @param mixed $departmentInput the submitted department id
     * @return int|null the department id, or null for an admin
     * @throws \DomainException when the role is unknown or the department breaks a rule
     */
    public function departmentFor(string $role, mixed $departmentInput): ?int
    {
        // Only the three known roles exist
        if (!in_array($role, self::ROLES, true)) {
            throw new \DomainException('Please choose a valid role.');
        }

        // The admin account administers the system and is not a routing step
        if ($role === 'admin') {
            return null;
        }

        // The submitted id must be a whole number naming an existing department
        $department = is_scalar($departmentInput) && ctype_digit((string) $departmentInput)
            ? Database::getInstance()->query("SELECT id, name FROM departments WHERE id = :id", [':id' => (int) $departmentInput])->fetch()
            : false;
        if (!$department) {
            throw new \DomainException('Please choose a department for this account.');
        }

        // Intake, the final receive and release all happen in the Records Unit
        if ($role === 'officer' && $department['name'] !== self::RECORDS_UNIT) {
            throw new \DomainException('Records Officers must belong to the Records Unit.');
        }

        return (int) $department['id'];
    }

    /**
     * Deactivate an account. The first deactivation time is kept on repeated calls.
     *
     * @throws \DomainException when a user tries to deactivate their own account
     */
    public function deactivate(int $userId, int $actingUserId): void
    {
        // Self-deactivation would lock the acting admin out mid-session
        if ($userId === $actingUserId) {
            throw new \DomainException('You cannot deactivate your own account.');
        }

        Database::getInstance()->query(
            'UPDATE users SET deactivated_at = NOW(), updated_at = NOW() WHERE id = :id AND deactivated_at IS NULL',
            [':id' => $userId]
        );
    }

    /**
     * Reactivate an account so the user can sign in again with their existing key.
     */
    public function reactivate(int $userId): void
    {
        Database::getInstance()->query(
            'UPDATE users SET deactivated_at = NULL, updated_at = NOW() WHERE id = :id',
            [':id' => $userId]
        );
    }
}
