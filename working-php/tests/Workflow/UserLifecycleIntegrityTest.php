<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use PDOException;
use Tests\Support\Fixtures;
use Tests\Support\IntegrityAudit;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Users whose signatures or keys are on record cannot be deleted, even with raw SQL.
 */
#[Group('thesis-integrity')]
class UserLifecycleIntegrityTest extends TestCase
{
    // Raw DELETE of a user who signed log entries is refused, and every chain still verifies
    public function test_raw_delete_of_a_signer_is_refused_and_chain_stays_verifiable(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $cashId = $this->fx['users']['cash.unit'];

        $this->assertSame('23000', $this->deleteError($cashId));

        // The signer still exists, so the audit verifies every entry
        $exists = Database::getInstance()->query('SELECT COUNT(*) c FROM users WHERE id = :id', [':id' => $cashId])->fetch();
        $this->assertSame(1, (int) $exists['c']);
        $results = IntegrityAudit::run($this->fx['users']['admin']);
        $this->assertEquals(100.0, $results['verified_percentage']);
        $this->assertSame(0, $results['invalid_signatures']);
    }

    // A user with a key history but no log entries cannot be deleted either
    public function test_key_history_blocks_user_delete(): void
    {
        $this->assertSame('23000', $this->deleteError($this->fx['users']['personnel.unit']));
    }

    // Try a raw DELETE and return the SQLSTATE it raised
    private function deleteError(int $userId): string
    {
        try {
            Database::getInstance()->getConnection()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        } catch (PDOException $e) {
            return (string) $e->getCode();
        }
        $this->fail('Expected the delete to be refused by a foreign key.');
    }
}
