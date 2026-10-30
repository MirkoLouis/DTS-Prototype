<?php

namespace Tests\Workflow;

use App\Core\AttemptThrottle;
use App\Common\Database\Database;
use App\Modules\Users\SecurityPinCacheService;
use App\Modules\Documents\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class PinRateLimitTest extends TestCase
{
    // Isolate the session superglobal used by SecurityPinCacheService
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    // Leave no session state behind for other tests
    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // Fetch the document row by id
    private function doc(int $id): array
    {
        return Database::getInstance()->query('SELECT * FROM documents WHERE id = :id', [':id' => $id])->fetch();
    }

    // Count the document's log rows with a given action
    private function logCount(int $id, string $action): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id AND action = :a', [':id' => $id, ':a' => $action])->fetch()['c'];
    }

    // Resolve the PIN as a controller would, then scan as cash.unit; returns the exception message or null
    private function scanWith(string $code, string $typedPin): ?string
    {
        $user = Fixtures::user('cash.unit');
        $_SESSION['user_id'] = $user->id;

        try {
            $pin = SecurityPinCacheService::resolvePin($typedPin);
            (new DocumentWorkflowService())->scanDocument($code, $user, (string) $pin);
            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    // Submit a document and route it to Cash Unit
    private function inTransitToCash(): array
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);

        return $s;
    }

    // Three wrong PINs time the user out; even the correct PIN is refused afterwards
    public function test_third_wrong_pin_times_out_signing(): void
    {
        $s = $this->inTransitToCash();

        // The first two failures report the ordinary wrong-PIN error
        for ($i = 0; $i < 2; $i++) {
            $message = $this->scanWith($s['tracking_code'], '000000');
            $this->assertNotNull($message);
            $this->assertNotSame(AttemptThrottle::MESSAGE, $message);
        }

        $this->assertSame(AttemptThrottle::MESSAGE, $this->scanWith($s['tracking_code'], '000000'));
        $this->assertSame(AttemptThrottle::MESSAGE, $this->scanWith($s['tracking_code'], TestDatabase::PIN));

        // Nothing was written and the document did not move
        $this->assertSame(0, $this->logCount($s['id'], 'Received'));
        $this->assertSame(0, $this->logCount($s['id'], 'System Auto-Freeze'));
        $this->assertSame('in_transit', $this->doc($s['id'])['status']);
    }

    // Once the timeout has passed the correct PIN signs again
    public function test_correct_pin_after_timeout_expires_succeeds(): void
    {
        $s = $this->inTransitToCash();
        for ($i = 0; $i < 3; $i++) {
            $this->scanWith($s['tracking_code'], '000000');
        }
        $this->assertSame(AttemptThrottle::MESSAGE, $this->scanWith($s['tracking_code'], TestDatabase::PIN));

        // Move the lock into the past instead of waiting a minute
        $key = AttemptThrottle::key('pin', (string) $this->fx['users']['cash.unit']);
        Database::getInstance()->query('UPDATE attempt_throttles SET locked_until = :t, first_failed_at = :f WHERE throttle_key = :k', [
            ':t' => time() - 1,
            ':f' => time() - 120,
            ':k' => $key,
        ]);

        $this->assertNull($this->scanWith($s['tracking_code'], TestDatabase::PIN));
        $this->assertSame(1, $this->logCount($s['id'], 'Received'));
        $this->assertSame('processing', $this->doc($s['id'])['status']);
    }

    // A cached PIN token is a valid PIN and never counts as a failure
    public function test_cached_token_does_not_count_as_failure(): void
    {
        $user = Fixtures::user('cash.unit');
        $_SESSION['user_id'] = $user->id;

        // A correct typed PIN issues a token; resolving it repeatedly records nothing
        SecurityPinCacheService::resolvePin(TestDatabase::PIN);
        $token = $_SESSION[SecurityPinCacheService::SESSION_ISSUED];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(TestDatabase::PIN, SecurityPinCacheService::resolvePin($token));
        }

        $key = AttemptThrottle::key('pin', (string) $user->id);
        $this->assertSame(0, AttemptThrottle::secondsLocked($key, time()));
        $row = Database::getInstance()->query('SELECT COUNT(*) c FROM attempt_throttles WHERE throttle_key = :k', [':k' => $key])->fetch();
        $this->assertSame(0, (int) $row['c']);
    }
}
