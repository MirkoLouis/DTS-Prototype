<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Users\UserService;
use Tests\TestCase;

/**
 * Deactivation and reactivation rules of user accounts.
 */
class UserServiceTest extends TestCase
{
    // Read a user's deactivated_at value
    private function deactivatedAt(int $userId): ?string
    {
        return Database::getInstance()->query('SELECT deactivated_at FROM users WHERE id = :id', [':id' => $userId])->fetch()['deactivated_at'];
    }

    // A second deactivation keeps the original timestamp
    public function test_deactivate_sets_timestamp_once(): void
    {
        $cash = $this->fx['users']['cash.unit'];
        $admin = $this->fx['users']['admin'];
        $service = new UserService();

        $service->deactivate($cash, $admin);
        $this->assertNotNull($this->deactivatedAt($cash));

        // Pin a known first timestamp, then deactivate again
        Database::getInstance()->query("UPDATE users SET deactivated_at = '2026-01-01 00:00:00' WHERE id = :id", [':id' => $cash]);
        $service->deactivate($cash, $admin);
        $this->assertSame('2026-01-01 00:00:00', $this->deactivatedAt($cash));
    }

    // Nobody can deactivate their own account
    public function test_deactivating_self_throws(): void
    {
        $admin = $this->fx['users']['admin'];

        try {
            (new UserService())->deactivate($admin, $admin);
            $this->fail('Expected a DomainException.');
        } catch (\DomainException $e) {
            $this->assertSame('You cannot deactivate your own account.', $e->getMessage());
        }
        $this->assertNull($this->deactivatedAt($admin));
    }

    // Reactivation clears the timestamp
    public function test_reactivate_clears_timestamp(): void
    {
        $cash = $this->fx['users']['cash.unit'];
        $service = new UserService();

        $service->deactivate($cash, $this->fx['users']['admin']);
        $service->reactivate($cash);

        $this->assertNull($this->deactivatedAt($cash));
    }
}
