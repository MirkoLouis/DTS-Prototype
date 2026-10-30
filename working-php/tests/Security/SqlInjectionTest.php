<?php

namespace Tests\Security;

use App\Common\Database\Database;
use App\Modules\Integrity\DigitalSignatureService;
use App\Modules\Integrity\Jobs\IntegrityCheckJob;
use Tests\Support\HttpClient;
use App\Research\ResultWriter;
use Tests\Support\TestDatabase;
use Tests\Support\ThreatActor;
use Tests\TestCase;

/**
 * SQL-injection suite (OWASP A03): fires classic payloads at every user-reachable query entry
 * point and proves the parameterised data layer neutralises them. Each (target, payload) pair is
 * recorded to storage/test-results/http-attacks.csv; the aggregate assertions at the end of the
 * single test fail loudly (as a FINDING) if any payload changed data, leaked a dialect error or a
 * password hash, blew up with a 500, or executed a time-based SLEEP.
 */
class SqlInjectionTest extends TestCase
{
    private const CSV = 'http-attacks.csv';

    // The payloads from the task brief, used verbatim
    private const PAYLOADS = [
        "' OR '1'='1",
        "1; DROP TABLE documents;-- ",
        '" UNION SELECT password FROM users-- ',
        'DEPED-\' OR 1=1#',
        "\\' OR SLEEP(3)-- ",
    ];

    // The text fields of POST /submit-document that carry free-form guest input
    private const SUBMIT_TEXT_FIELDS = [
        'guest_name', 'guest_email', 'guest_phone', 'district', 'department', 'title', 'other_purpose_text',
    ];

    // Substrings that must never appear in any response body
    private const LEAK_MARKERS = ['SQLSTATE', '$argon2', '$2y$'];

    /**
     * Fire every payload at every target, record a CSV row each, then assert the invariants hold.
     */
    public function test_sql_injection_payloads_have_no_effect(): void
    {
        $client = HttpClient::start();

        // Baseline row counts; only legitimate guest submissions (payload stored as plain text) may change them
        $documentsBefore = $this->rowCount('documents');
        $usersBefore = $this->rowCount('users');
        $logsBefore = $this->rowCount('document_logs');

        $maxStatus = 0;
        $leaked = [];
        $slowSleep = [];
        $expectedNewDocuments = 0;

        // Target 1: POST /login (email). Each payload is a distinct email, so the 3-strike lockout never trips.
        $authBypass = [];
        foreach (self::PAYLOADS as $payload) {
            $client->get('/login');
            [$status, $body, $elapsed, $location] = $this->timed(fn () => $client->post('/login', ['email' => $payload, 'password' => 'irrelevant']));

            // The payload must not authenticate: bounced back to /login, and /dashboard still demands a login
            $dashboardLocation = $client->get('/dashboard')['headers']['location'] ?? '';
            $notAuthenticated = str_contains($location, '/login') && str_contains($dashboardLocation, '/login');
            if (!$notAuthenticated) {
                $authBypass[] = "{$payload} (login Location={$location}, /dashboard Location={$dashboardLocation})";
            }
            $authNote = sprintf('login->%s; /dashboard->%s', $location ?: '(none)', $dashboardLocation ?: '(none)');
            $this->observe('POST /login', $payload, $status, $body, $elapsed, $maxStatus, $leaked, $slowSleep, $notAuthenticated, $authNote);
        }

        // Target 2: GET /track?codes=... (GuestController::track reads the `codes` query parameter)
        foreach (self::PAYLOADS as $payload) {
            [$status, $body, $elapsed] = $this->timed(fn () => $client->get('/track?codes=' . rawurlencode($payload)));
            $this->observe('GET /track', $payload, $status, $body, $elapsed, $maxStatus, $leaked, $slowSleep);
        }

        // Target 3: GET /api/track/status?codes=... (getStatusUpdates reads the `codes` query parameter)
        foreach (self::PAYLOADS as $payload) {
            [$status, $body, $elapsed] = $this->timed(fn () => $client->get('/api/track/status?codes=' . rawurlencode($payload)));
            $this->observe('GET /api/track/status', $payload, $status, $body, $elapsed, $maxStatus, $leaked, $slowSleep);
        }

        // Target 4: POST /intake/find (tracking_code), authenticated as the Records officer
        $officer = HttpClient::start();
        $officer->loginAs('records.unit');
        foreach (self::PAYLOADS as $payload) {
            $officer->get('/intake');
            [$status, $body, $elapsed] = $this->timed(fn () => $officer->post('/intake/find', ['tracking_code' => $payload]));
            $this->observe('POST /intake/find', $payload, $status, $body, $elapsed, $maxStatus, $leaked, $slowSleep);
        }

        // Target 5: every text field of POST /submit-document, one field poisoned per request
        $purposeId = $this->officialPurposeId();
        foreach (self::PAYLOADS as $payload) {
            foreach (self::SUBMIT_TEXT_FIELDS as $field) {
                $client->get('/');
                $fields = $this->validSubmission($purposeId);
                $fields[$field] = $payload;
                [$status, $body, $elapsed, $location] = $this->timed(fn () => $client->post('/submit-document', $fields));

                // A redirect to /success means the row was stored verbatim; a bounce to / means validation rejected it
                $accepted = str_contains($location, '/success');
                if ($accepted) {
                    $expectedNewDocuments++;
                }
                $this->observe('POST /submit-document[' . $field . ']', $payload, $status, $body, $elapsed, $maxStatus, $leaked, $slowSleep);
            }
        }

        // Summary row: row counts after every payload, written before any assertion can stop the test
        $usersAfter = $this->rowCount('users');
        $documentsAfter = $this->rowCount('documents');
        $logsAfter = $this->rowCount('document_logs');
        $countsOk = $usersAfter === $usersBefore
            && $documentsAfter === $documentsBefore + $expectedNewDocuments
            && $logsAfter === $logsBefore + $expectedNewDocuments;
        $this->recordRow(
            'sql_injection_row_counts',
            ThreatActor::KIOSK_WALKUP,
            'documents, users, document_logs',
            sprintf('users %d; documents %d; document_logs %d (+%d legitimate plain-text submissions)', $usersBefore, $documentsBefore + $expectedNewDocuments, $logsBefore + $expectedNewDocuments, $expectedNewDocuments),
            sprintf('users %d; documents %d; document_logs %d', $usersAfter, $documentsAfter, $logsAfter),
            $countsOk
        );

        // Summary row: the integrity audit after every payload
        $verified = $this->auditVerifiedPercentage();
        $this->recordRow(
            'sql_injection_integrity_audit',
            ThreatActor::KIOSK_WALKUP,
            'IntegrityCheckJob',
            'verified_percentage 100.0',
            sprintf('verified_percentage %s', $verified),
            $verified === 100.0
        );

        // 1. No payload caused a server error
        $this->assertLessThan(500, $maxStatus, "FINDING [sqli]: a payload produced HTTP {$maxStatus}.");

        // 2. No response leaked a dialect error or a password hash
        $this->assertSame([], $leaked, 'FINDING [sqli]: response body leaked sensitive markers: ' . implode('; ', $leaked));

        // 3. No login payload authenticated
        $this->assertSame([], $authBypass, 'FINDING [sqli_login_bypass]: a login payload authenticated: ' . implode('; ', $authBypass));

        // 4. Row counts changed only by the legitimate guest submissions that stored the payload as plain text
        $this->assertSame($usersBefore, $usersAfter, 'FINDING [sqli]: the users table row count changed.');
        $this->assertSame($documentsBefore + $expectedNewDocuments, $documentsAfter, 'FINDING [sqli]: documents row count changed beyond legitimate submissions.');
        $this->assertSame($logsBefore + $expectedNewDocuments, $logsAfter, 'FINDING [sqli]: document_logs row count changed beyond legitimate submissions.');

        // 5. The time-based SLEEP payload never executed (also recorded per row)
        $this->assertSame([], $slowSleep, 'FINDING [sqli]: a SLEEP payload delayed a response (time-based injection): ' . implode('; ', $slowSleep));

        // 6. The cryptographic audit still reports a fully intact system
        $this->assertSame(100.0, $verified, 'FINDING [sqli]: integrity audit no longer reports 100%.');
    }

    /**
     * Record one CSV row and fold the result into the shared accumulators.
     */
    private function observe(string $target, string $payload, int $status, string $body, float $elapsed, int &$maxStatus, array &$leaked, array &$slowSleep, bool $extraOk = true, string $extraNote = ''): void
    {
        // Track the worst status so one 500 anywhere fails the test
        $maxStatus = max($maxStatus, $status);

        // Flag any leaked marker in the response body
        $foundMarkers = [];
        foreach (self::LEAK_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                $foundMarkers[] = $marker;
                $leaked[] = "{$target} <= {$payload} leaked {$marker}";
            }
        }

        // A SLEEP(3) payload that took 2s or more means the database actually slept
        $isSleep = str_contains($payload, 'SLEEP');
        if ($isSleep && $elapsed >= 2.0) {
            $slowSleep[] = sprintf('%s <= %s took %.2fs', $target, $payload, $elapsed);
        }

        // Target-specific checks (e.g. login must not authenticate) also decide the row
        $passed = $status < 500 && $foundMarkers === [] && !($isSleep && $elapsed >= 2.0) && $extraOk;

        // The login target additionally promises that the payload did not authenticate
        $expected = 'no data change, no error/hash leak, no time delay';
        if ($target === 'POST /login') {
            $expected .= ', not authenticated';
        }

        $observed = sprintf('HTTP %d; %s; %.2fs', $status, $foundMarkers ? 'LEAK:' . implode(',', $foundMarkers) : 'no leak', $elapsed);
        if ($extraNote !== '') {
            $observed .= '; ' . $extraNote;
        }

        $this->recordRow('sql_injection', $target === 'POST /intake/find' ? ThreatActor::LAN_DEVICE : ThreatActor::KIOSK_WALKUP, $target . ' <= ' . $payload, $expected, $observed, $passed);
    }

    /**
     * Append one SQL-injection row (per-payload or summary) to the shared CSV.
     */
    private function recordRow(string $attack, string $actor, string $target, string $expected, string $observed, bool $passed): void
    {
        ResultWriter::append(self::CSV, [
            'attack' => $attack,
            'threat_actor' => $actor,
            'owasp_2021' => 'A03',
            'target' => $target,
            'expected' => $expected,
            'observed' => $observed,
            'passed' => $passed ? 'yes' : 'no',
        ]);
    }

    /**
     * Time a single HTTP call and return [status, body, elapsed seconds, location header].
     */
    private function timed(callable $call): array
    {
        $start = microtime(true);
        $response = $call();
        $elapsed = microtime(true) - $start;

        return [$response['status'], $response['body'], $elapsed, $response['headers']['location'] ?? ''];
    }

    /**
     * A fully valid guest submission payload, before one field is poisoned.
     */
    private function validSubmission(int $purposeId): array
    {
        return [
            'guest_name' => 'Juan Dela Cruz',
            'guest_email' => 'juan@example.com',
            'guest_phone' => '09171234567',
            'district' => 'Iligan City',
            'department' => 'Records Unit',
            'title' => 'Baseline Document',
            'purpose_id' => $purposeId,
            'other_purpose_text' => '',
        ];
    }

    /**
     * The id of any official purpose, used so the submission passes the foreign-key constraint.
     */
    private function officialPurposeId(): int
    {
        $row = Database::getInstance()->query('SELECT id FROM purposes WHERE is_official = 1 ORDER BY id LIMIT 1')->fetch();

        return (int) $row['id'];
    }

    /**
     * Count the rows of a table; also proves the table still exists after the DROP TABLE payload.
     */
    private function rowCount(string $table): int
    {
        return (int) Database::getInstance()->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
    }

    /**
     * Run one integrity audit exactly as the System Health page does and return the verified percentage.
     */
    private function auditVerifiedPercentage(): float
    {
        // The audit reloads key history from the database, so clear the per-process cache first
        DigitalSignatureService::clearKeyCache();

        $db = Database::getInstance();
        $id = bin2hex(random_bytes(16));
        $db->query("INSERT INTO integrity_checks (id, user_id, status, progress, created_at, updated_at) VALUES (:id, :u, 'queued', 0, NOW(), NOW())", [
            ':id' => $id,
            ':u' => $this->fx['users']['admin'],
        ]);

        (new IntegrityCheckJob($id))->handle();

        $check = $db->query('SELECT status, results, error_message FROM integrity_checks WHERE id = :id', [':id' => $id])->fetch();
        $this->assertSame('completed', $check['status'], 'Audit failed: ' . (string) $check['error_message']);

        return (float) json_decode($check['results'], true)['verified_percentage'];
    }
}
