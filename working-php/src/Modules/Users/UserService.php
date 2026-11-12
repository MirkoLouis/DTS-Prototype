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
