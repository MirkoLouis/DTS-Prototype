<?php

namespace Tests\Security;

use App\Core\Database;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use App\Research\ResultWriter;
use Tests\Support\TestDatabase;
use Tests\Support\ThreatActor;
use Tests\TestCase;

/**
 * Attacks on the encrypted browser-side PIN cache token (OWASP A07). The token seals the PIN, the
 * owner's user id and an absolute expiry under a key that lives only in the issuing PHP session.
 * These cases prove a token is useless in another session, after tampering, or after it expires,
 * and that a wrong typed PIN signs nothing. Each case writes a row to http-attacks.csv.
 */
class PinTokenTest extends TestCase
{
    private const CSV = 'http-attacks.csv';

    /**
     * A token issued in one session is rejected when replayed from a different session.
     */
    public function test_cached_token_from_another_session_is_rejected(): void
    {
        // Session A legitimately earns a cache token
        [, $token] = $this->issueToken();
        $this->assertStringStartsWith('pt1.', $token, 'Harness could not obtain a PIN cache token.');

        // A real document the replayed token would otherwise receive
        [$target, $logsBefore] = $this->scannableDocument();

        // Session B is a fresh login as the same user; it never held the sealing key
        $sessionB = HttpClient::start();
        $sessionB->loginAs('cash.unit');
        $sessionB->get('/tasks');
        $sessionB->post('/documents/scan', ['tracking_code' => $target['tracking_code'], 'pin' => $token]);
        $page = $sessionB->get('/tasks')['body'];

        // The server could not open the foreign token, told the browser to drop it, and changed nothing
        [$rejected, $observed] = $this->rejectionOutcome($page, $target['id'], $logsBefore);
        $this->record('pin_token_replay_cross_session', ThreatActor::LAN_DEVICE, 'A07', 'POST /documents/scan', 'token rejected (session-bound), no new log, no state change', $observed, $rejected);
        $this->assertTrue($rejected, 'FINDING [pin_token_replay_cross_session]: a token from another session was honoured. ' . $observed);
    }

    /**
     * A token whose ciphertext is altered by one character is rejected (authenticated encryption).
     */
    public function test_tampered_token_is_rejected(): void
    {
        // Session A holds the sealing key, so only the tampering can explain a rejection
        [$client, $token] = $this->issueToken();
        $this->assertStringStartsWith('pt1.', $token, 'Harness could not obtain a PIN cache token.');

        // Flip one character inside the base64 body of the token
        $tampered = $this->flipOneCharacter($token);

        // A real document the tampered token would otherwise receive
        [$target, $logsBefore] = $this->scannableDocument();

        $client->get('/tasks');
        $client->post('/documents/scan', ['tracking_code' => $target['tracking_code'], 'pin' => $tampered]);
        $page = $client->get('/tasks')['body'];

        [$rejected, $observed] = $this->rejectionOutcome($page, $target['id'], $logsBefore);
        $this->record('pin_token_tampered', ThreatActor::LAN_DEVICE, 'A07', 'POST /documents/scan', 'token rejected (MAC failure), no new log, no state change', $observed, $rejected);
        $this->assertTrue($rejected, 'FINDING [pin_token_tampered]: a tampered token was accepted. ' . $observed);
    }

    /**
     * A token is rejected once PIN_CACHE_DURATION has elapsed (set to 2s in .env.testing).
     */
    public function test_expired_token_is_rejected(): void
    {
        [$client, $token] = $this->issueToken();
        $this->assertStringStartsWith('pt1.', $token, 'Harness could not obtain a PIN cache token.');

        // A real document the expired token would otherwise receive
        [$target, $logsBefore] = $this->scannableDocument();

        // Wait past the 2-second cache window
        sleep(3);

        $client->get('/tasks');
        $client->post('/documents/scan', ['tracking_code' => $target['tracking_code'], 'pin' => $token]);
        $page = $client->get('/tasks')['body'];

        [$rejected, $observed] = $this->rejectionOutcome($page, $target['id'], $logsBefore);
        $this->record('pin_token_expired', ThreatActor::STAFF_INSIDER, 'A07', 'POST /documents/scan', 'token rejected (expired), no new log, no state change', $observed, $rejected);
        $this->assertTrue($rejected, 'FINDING [pin_token_expired]: an expired token was accepted. ' . $observed);
    }

    /**
     * A wrong typed PIN signs nothing: the attempted action adds no log entry and no state change.
     */
    public function test_wrong_pin_creates_no_log_entry(): void
    {
        // A document in transit to the Cash Unit, so the policy itself would permit this staff to act
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);
        $logsBefore = $this->logCount($document['id']);

        $staff = HttpClient::start();
        $staff->loginAs('cash.unit');
        $staff->get('/tasks');

        // A single wrong PIN (one failure, below the 3-strike lockout)
        $staff->post('/documents/scan', ['tracking_code' => $document['tracking_code'], 'pin' => '000000']);

        $logsAfter = $this->logCount($document['id']);
        $row = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $document['id']])->fetch();

        // The signature step fails and rolls the transaction back, so nothing is written
        $noLog = $logsAfter === $logsBefore && $row['status'] === 'in_transit';
        $observed = sprintf('logs %d -> %d, status=%s', $logsBefore, $logsAfter, $row['status']);
        $this->record('wrong_pin_no_log', ThreatActor::STAFF_INSIDER, 'A07', 'POST /documents/scan', 'no new log entry, no state change', $observed, $noLog);
        $this->assertTrue($noLog, 'FINDING [wrong_pin_no_log]: a wrong PIN produced a log entry or state change. ' . $observed);
    }

    /**
     * Earn a genuine PIN cache token: a Cash staff member receives a document at their step with a
     * typed PIN, then reads the token the app layout wrote to sessionStorage.
     *
     * @return array{0: HttpClient, 1: string}
     */
    private function issueToken(): array
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);

        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $client->get('/tasks');

        // A successful typed-PIN action makes the server issue the cache token
        $client->post('/documents/scan', ['tracking_code' => $document['tracking_code'], 'pin' => TestDatabase::PIN]);

        // The token is handed to the browser on the next app-layout render
        $body = $client->get('/tasks')['body'];
        $token = '';
        if (preg_match("/dts_pin_token',\\s*\"([^\"]+)\"/", $body, $m)) {
            $token = $m[1];
        }

        return [$client, $token];
    }

    /**
     * A document in transit to the Cash Unit, so cash.unit would receive it if the PIN resolved.
     *
     * @return array{0: array{id:int, tracking_code:string}, 1: int} the document and its log count
     */
    private function scannableDocument(): array
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);

        return [$document, $this->logCount($document['id'])];
    }

    /**
     * Decide whether a token was rejected: browser told to clear it, PIN demanded, no new log, status unchanged.
     *
     * @return array{0: bool, 1: string} rejected flag and the observed summary
     */
    private function rejectionOutcome(string $page, int $documentId, int $logsBefore): array
    {
        $cleared = str_contains($page, "removeItem('dts_pin_token')");
        $pinDemanded = str_contains($page, 'Security PIN is required');
        $logsAfter = $this->logCount($documentId);
        $status = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $documentId])->fetch()['status'];

        $rejected = $cleared && $pinDemanded && $logsAfter === $logsBefore && $status === 'in_transit';
        $observed = sprintf('token cleared=%s, PIN demanded=%s, logs %d -> %d, status=%s', $cleared ? 'yes' : 'no', $pinDemanded ? 'yes' : 'no', $logsBefore, $logsAfter, $status);

        return [$rejected, $observed];
    }

    /**
     * Flip a single character inside the token body to simulate tampering without changing length.
     */
    private function flipOneCharacter(string $token): string
    {
        // Pick a position well inside the base64 ciphertext, past the "pt1." prefix
        $pos = max(4, strlen($token) - 6);
        $token[$pos] = $token[$pos] === 'A' ? 'B' : 'A';

        return $token;
    }

    /**
     * Count the log entries belonging to a document.
     */
    private function logCount(int $documentId): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) AS c FROM document_logs WHERE document_id = :id', [':id' => $documentId])->fetch()['c'];
    }

    /**
     * Append one result row to the shared CSV.
     */
    private function record(string $attack, string $actor, string $owasp, string $target, string $expected, string $observed, bool $passed): void
    {
        ResultWriter::append(self::CSV, [
            'attack' => $attack,
            'threat_actor' => $actor,
            'owasp_2021' => $owasp,
            'target' => $target,
            'expected' => $expected,
            'observed' => $observed,
            'passed' => $passed ? 'yes' : 'no',
        ]);
    }
}
