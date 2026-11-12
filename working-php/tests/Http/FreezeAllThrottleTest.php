<?php

namespace Tests\Http;

use App\Modules\Auth\AttemptThrottleService;
use App\Common\Database\Database;
use App\Modules\Integrity\Jobs\IntegrityCheckJob;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class FreezeAllThrottleTest extends TestCase
{
    // Remove the integrity result this test wrote
    protected function tearDown(): void
    {
        @unlink(IntegrityCheckJob::resultPath());
        parent::tearDown();
    }

    // A timed-out admin must see the timeout message, not "Successfully frozen 0 documents"
    public function test_freeze_all_surfaces_pin_timeout(): void
    {
        // Flag one tampered document in the testing integrity result
        $s = Fixtures::submit();
        file_put_contents(IntegrityCheckJob::resultPath(), json_encode(['mismatched_document_tracking_codes' => [$s['tracking_code']]]));

        // Log in, then time the admin's PIN out
        $client = HttpClient::start();
        $client->loginAs('admin');
        $key = AttemptThrottleService::key('pin', (string) $this->fx['users']['admin']);
        for ($i = 0; $i < 3; $i++) {
            AttemptThrottleService::recordFailure($key, time());
        }

        // Freeze-all with the correct PIN while timed out
        $client->get('/system-overview');
        $response = $client->post('/system-health/freeze-all', ['pin' => TestDatabase::PIN]);
        $this->assertSame(302, $response['status']);
        $page = $client->get('/system-overview')['body'];

        // The timeout is surfaced and nothing was frozen
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $page);
        $this->assertStringNotContainsString('Successfully frozen', $page);
        $status = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $s['id']])->fetch()['status'];
        $this->assertSame('pending', $status);
    }
}
