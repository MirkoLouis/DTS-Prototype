<?php

namespace Tests\Workflow;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Security\IntegrityKey;
use App\Common\Security\IntegrityKeyMissingException;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\DocumentWorkflowService;
use App\Modules\Integrity\PreActionIntegrityService;
use App\Modules\Users\UserModel;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\IntegrityAudit;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * No key, no pass: without APP_INTEGRITY_KEY the worker does not start, the integrity check reports
 * "seal not configured", and the pre-action check refuses the action (without freezing anything).
 * The key is removed through the test seam IntegrityKey::TEST_OVERRIDE_VARIABLE; .env.testing is not edited.
 */
#[Group('thesis-integrity')]
class MissingIntegrityKeyTest extends TestCase
{
    // Leave no override and no redirected error log behind
    protected function tearDown(): void
    {
        putenv(IntegrityKey::TEST_OVERRIDE_VARIABLE);
        ini_restore('error_log');

        // Drop any server that was started without the key, so later tests start one with it
        HttpClient::stop();
        parent::tearDown();
    }

    // Remove the key for the rest of the test
    private function withoutKey(): void
    {
        putenv(IntegrityKey::TEST_OVERRIDE_VARIABLE . '=' . IntegrityKey::TEST_NO_KEY);

        // A server started earlier in the run has the key; the next HTTP request must start one without it
        HttpClient::stop();
    }

    // Fetch a document row
    private function doc(int $id): array
    {
        return Database::getInstance()->query('SELECT * FROM documents WHERE id = :id', [':id' => $id])->fetch();
    }

    // Count the log rows of a document
    private function logCount(int $id): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id', [':id' => $id])->fetch()['c'];
    }

    // The worker exits at once with a clear message, before it connects to the database
    public function test_worker_refuses_to_start(): void
    {
        $process = proc_open([PHP_BINARY, BASE_PATH . '/console.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['APP_ENV' => 'testing', IntegrityKey::TEST_OVERRIDE_VARIABLE => IntegrityKey::TEST_NO_KEY]));

        // A worker that did start would run forever: give the refusal a few seconds, then stop the process and fail
        $deadline = microtime(true) + 15;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(100000);
        }
        $stillRunning = proc_get_status($process)['running'];
        if ($stillRunning) {
            proc_terminate($process);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertFalse($stillRunning, 'The worker started although no key is configured');

        $this->assertSame(1, proc_close($process));
        $this->assertStringContainsString('Worker not started', (string) $stderr);
        $this->assertStringContainsString('APP_INTEGRITY_KEY', (string) $stderr);
        $this->assertStringNotContainsString('Starting async queue worker', (string) $stdout);
    }

    // The dev and prod start scripts refuse through the shared helper (the same message, thrown before anything starts)
    public function test_start_scripts_refuse_through_the_shared_helper(): void
    {
        require_once BASE_PATH . '/scripts/server-lib.php';
        $this->withoutKey();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_INTEGRITY_KEY');
        assertIntegrityKeyConfigured();
    }

    // The integrity check says "seal not configured" and shows no seal as verified
    public function test_integrity_check_reports_seal_not_configured(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $this->withoutKey();

        $results = IntegrityAudit::run($this->fx['users']['admin']);

        $this->assertFalse($results['seal_configured']);
        $this->assertSame(0, $results['invalid_entry_seals']);
        $this->assertSame(0, $results['invalid_chain_seals']);

        // System Overview shows the message (never a green status without a key)
        $client = HttpClient::start();
        $client->loginAs('admin');
        $body = $client->get('/system-overview')['body'];
        $this->assertStringContainsString('seal not configured', $body);
        $this->assertStringContainsString('text-red-500', substr($body, (int) strpos($body, 'id="verified-percentage"') - 40, 120));
    }

    // The pre-action check refuses with a clear error and logs it; the document is not frozen and nothing is written
    public function test_pre_action_check_refuses_without_freezing(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);
        $logsBefore = $this->logCount($document['id']);
        $rowBefore = $this->doc($document['id']);

        // Collect what the check logs in a file under .test/
        $errorLog = Config::testPath('logs/php-error.log');
        @mkdir(dirname($errorLog), 0770, true);
        @unlink($errorLog);
        ini_set('error_log', $errorLog);
        $this->withoutKey();

        // The check itself throws, naming the key
        try {
            PreActionIntegrityService::passes($rowBefore);
            $this->fail('The pre-action check must refuse without a key.');
        } catch (IntegrityKeyMissingException $e) {
            // Users see a generic message; the operator detail goes to the error log only
            $this->assertSame(IntegrityKey::USER_MESSAGE, $e->getMessage());
        }

        // So does the policy and the workflow action that uses it (receive at Cash Unit)
        $cash = Fixtures::user('cash');
        try {
            (new DocumentWorkflowService())->scanDocument($document['tracking_code'], $cash, TestDatabase::PIN);
            $this->fail('The workflow action must refuse without a key.');
        } catch (IntegrityKeyMissingException $e) {
            $this->assertSame(IntegrityKey::USER_MESSAGE, $e->getMessage());
        }

        // Nothing changed and nothing was frozen
        $this->assertSame('in_transit', $this->doc($document['id'])['status']);
        $this->assertEquals($rowBefore, $this->doc($document['id']));
        $this->assertSame($logsBefore, $this->logCount($document['id']));
        $logged = (string) file_get_contents($errorLog);
        $this->assertStringContainsString('Pre-action integrity check refused document', $logged);
        $this->assertStringContainsString('APP_INTEGRITY_KEY', $logged);
    }

    // A guest submission without a key fails cleanly: no document, no log, and a generic message without operator text
    public function test_guest_submission_fails_cleanly_without_a_key(): void
    {
        $this->withoutKey();
        $client = HttpClient::start();
        $client->get('/');
        $response = $client->post('/submit-document', [
            'guest_name' => 'Key Check Guest',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '09171234567',
            'district' => 'N/A',
            'department' => 'Records Unit',
            'title' => 'No key submission',
            'purpose_id' => (string) reset($this->fx['purposes']),
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame(0, (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents')->fetch()['c']);
        $this->assertSame(0, (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs')->fetch()['c']);
        $page = $client->get('/')['body'];
        $this->assertStringContainsString(IntegrityKey::USER_MESSAGE, $page);
        $this->assertStringNotContainsString('APP_INTEGRITY_KEY', $page);
        $this->assertStringNotContainsString('.env', $page);
    }

    // Writing a log entry without a key fails closed: no document and no entry is created
    public function test_log_entries_are_not_written_without_a_key(): void
    {
        $this->withoutKey();
        $documentsBefore = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents')->fetch()['c'];

        try {
            Fixtures::submit();
            $this->fail('A submission must fail without a key.');
        } catch (IntegrityKeyMissingException $e) {
            $this->assertSame(IntegrityKey::USER_MESSAGE, $e->getMessage());
        }

        $this->assertSame($documentsBefore, (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents')->fetch()['c']);
        $this->assertSame(0, (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs')->fetch()['c']);
    }
}
