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
 * Front-end attack tactics over real HTTP: CSRF, stored XSS, forged form fields, wrong-department
 * scans, cross-step completion, privilege escalation to admin routes, and IDOR-style viewing of a
 * document outside the actor's route. Every case appends a row to http-attacks.csv. Assertions that
 * fail are FINDINGS (predicted: forged final_route accepted, and DocumentController::show skipping
 * DocumentPolicy::view) and are reported, not papered over.
 */
class FrontendAttackTest extends TestCase
{
    private const CSV = 'http-attacks.csv';

    // The two stored-XSS payloads from the brief
    private const XSS_PAYLOADS = [
        '<script>alert(1)</script>',
        '"><img src=x onerror=alert(1)>',
    ];

    /**
     * A POST with no CSRF token is rejected with 419 before any controller runs.
     */
    public function test_post_without_csrf_token_returns_419(): void
    {
        $client = HttpClient::start();

        // Load the form first so a session and its CSRF token exist; only the missing token can then explain a 419
        $tokenInPage = $this->extractToken($client->get('/')['body']);
        $this->assertNotSame('', $tokenInPage, 'Harness error: the guest form carried no CSRF token.');

        // Post to a state-changing endpoint with the token deliberately withheld
        $response = $client->post('/submit-document', ['guest_name' => 'No Token'], false);

        $passed = $response['status'] === 419;
        $this->record('csrf_missing_token', ThreatActor::LAN_DEVICE, 'A01', 'POST /submit-document', 'HTTP 419', 'HTTP ' . $response['status'], $passed);
        $this->assertSame(419, $response['status'], 'FINDING [csrf_missing_token]: expected 419, got ' . $response['status']);
    }

    /**
     * A CSRF token minted in a different session is rejected with 419.
     */
    public function test_post_with_other_sessions_csrf_token_returns_419(): void
    {
        // Session A hands out a valid token for its own session
        $sessionA = HttpClient::start();
        $tokenA = $this->extractToken($sessionA->get('/login')['body']);
        $this->assertNotSame('', $tokenA, 'Could not read session A CSRF token.');

        // Session B owns a different token; replaying A's token must fail the constant-time comparison
        $sessionB = HttpClient::start();
        $sessionB->get('/login');
        $response = $sessionB->post('/submit-document', ['guest_name' => 'Replay', 'csrf_token' => $tokenA]);

        $passed = $response['status'] === 419;
        $this->record('csrf_cross_session_token', ThreatActor::LAN_DEVICE, 'A01', 'POST /submit-document', 'HTTP 419', 'HTTP ' . $response['status'], $passed);
        $this->assertSame(419, $response['status'], 'FINDING [csrf_cross_session_token]: a foreign session token was accepted (HTTP ' . $response['status'] . ').');
    }

    /**
     * Stored XSS in a submission is HTML-escaped on every surface that renders it.
     */
    public function test_stored_xss_in_submission_is_escaped(): void
    {
        $officer = HttpClient::start();
        $officer->loginAs('records.unit');
        $guest = HttpClient::start();

        // Each payload gets its own document, carried in both the title and the submitter name
        foreach (self::XSS_PAYLOADS as $payload) {
            $document = Fixtures::submit(['title' => $payload, 'guest_name' => $payload]);

            // Finalise intake so the document appears in the officer's Recently Handled list
            Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);
            $escaped = htmlspecialchars($payload);

            // Surface 1: the officer intake list (renders the submitter name)
            $this->assertXssEscaped('GET /intake', $payload, $escaped, $officer->get('/intake')['body']);

            // Surface 2: the officer document detail page (renders title and submitter name)
            $this->assertXssEscaped('GET /documents/{code}', $payload, $escaped, $officer->get('/documents/' . $document['tracking_code'])['body']);

            // Surface 3: the public tracking module (renders the submitter name)
            $this->assertXssEscaped('GET /api/track/module/{code}', $payload, $escaped, $guest->get('/api/track/module/' . $document['tracking_code'])['body']);
        }
    }

    /**
     * A forged final_route naming a department that does not exist must be rejected at intake.
     * PREDICTED FINDING: finalizeIntake stores the route verbatim with a null department id.
     */
    public function test_forged_final_route_with_unknown_department_is_rejected(): void
    {
        $officer = HttpClient::start();
        $officer->loginAs('records.unit');

        // A fresh pending document to finalise
        $document = Fixtures::submit();
        $officer->get('/documents/' . $document['id'] . '/manage');

        // Forge a route that routes the document to a non-existent unit
        $forgedRoute = json_encode(['Ministry of Magic']);
        $officer->post('/documents/' . $document['id'] . '/finalize', ['final_route' => $forgedRoute, 'pin' => TestDatabase::PIN]);

        // Observe the resulting database state
        $row = Database::getInstance()->query('SELECT status, finalized_route, current_department_id FROM documents WHERE id = :id', [':id' => $document['id']])->fetch();
        $accepted = $row['status'] === 'in_transit';
        $observed = sprintf('status=%s, current_department_id=%s, route=%s', $row['status'], var_export($row['current_department_id'], true), $row['finalized_route']);

        $this->record('forged_final_route_unknown_department', ThreatActor::STAFF_INSIDER, 'A04', 'POST /documents/{id}/finalize', 'rejected (route validated against known departments)', $observed, !$accepted);
        $this->assertNotSame('in_transit', $row['status'], 'FINDING [forged_final_route_unknown_department]: an unknown department was accepted and routed. ' . $observed);
    }

    /**
     * A staff member scanning a document routed to another department is denied over HTTP.
     */
    public function test_scan_by_wrong_department_is_rejected(): void
    {
        // Document in transit to the Cash Unit
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);

        // Personnel staff tries to receive it
        $staff = HttpClient::start();
        $staff->loginAs('personnel.unit');
        $staff->get('/tasks');
        $staff->post('/documents/scan', ['tracking_code' => $document['tracking_code'], 'pin' => TestDatabase::PIN]);

        // The denial is flashed as a console_error, surfaced on the next app-layout page
        $page = $staff->get('/tasks')['body'];
        $row = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $document['id']])->fetch();

        // R6: wrong-department staff are blocked by DocumentPolicy::process with "Action Denied: ..."
        $denied = str_contains($page, 'Action Denied') && $row['status'] === 'in_transit';
        $observed = sprintf('status=%s, message=%s', $row['status'], $this->consoleError($page) ?: '(none)');

        $this->record('scan_wrong_department', ThreatActor::STAFF_INSIDER, 'A04', 'POST /documents/scan', 'denied: "Action Denied: ..."', $observed, $denied);
        $this->assertTrue($denied, 'FINDING [scan_wrong_department]: a wrong-department scan was not denied as expected. ' . $observed);
    }

    /**
     * A staff member cannot complete a task that sits at another department's step.
     */
    public function test_staff_cannot_complete_task_not_at_their_step(): void
    {
        // Document being processed by the Personnel Unit (step 1)
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'processing', ['Personnel Unit']);

        // Cash staff tries to complete the Personnel step
        $staff = HttpClient::start();
        $staff->loginAs('cash.unit');
        $staff->get('/tasks');
        $staff->post('/tasks/' . $document['id'] . '/complete', ['pin' => TestDatabase::PIN]);

        $page = $staff->get('/tasks')['body'];
        $row = Database::getInstance()->query('SELECT status, current_step FROM documents WHERE id = :id', [':id' => $document['id']])->fetch();

        // Still at Personnel's step, processing, with the "Action Denied" message shown
        $denied = str_contains($page, 'Action Denied') && $row['status'] === 'processing' && (int) $row['current_step'] === 1;
        $observed = sprintf('status=%s, current_step=%s, message=%s', $row['status'], $row['current_step'], $this->consoleError($page) ?: '(none)');

        $this->record('cross_step_completion', ThreatActor::STAFF_INSIDER, 'A04', 'POST /tasks/{id}/complete', 'denied: "Action Denied: ..."', $observed, $denied);
        $this->assertTrue($denied, 'FINDING [cross_step_completion]: staff completed a task not at their step. ' . $observed);
    }

    /**
     * A staff member cannot reach admin-only routes (403 from RoleMiddleware).
     */
    public function test_staff_cannot_reach_admin_routes(): void
    {
        $staff = HttpClient::start();
        $staff->loginAs('cash.unit');

        // A document whose freeze endpoint the staff member will probe
        $document = Fixtures::submit();

        // Two admin-only GET pages
        foreach (['/users', '/system-overview'] as $route) {
            $status = $staff->get($route)['status'];
            $passed = $status === 403;
            $this->record('privilege_escalation_admin_route', ThreatActor::STAFF_INSIDER, 'A01', 'GET ' . $route, 'HTTP 403', 'HTTP ' . $status, $passed);
            $this->assertSame(403, $status, "FINDING [privilege_escalation_admin_route]: staff reached {$route} (HTTP {$status}).");
        }

        // An admin-only POST (with a valid CSRF token, so the 403 comes from RBAC not CSRF)
        $staff->get('/tasks');
        $status = $staff->post('/documents/' . $document['tracking_code'] . '/freeze', [])['status'];
        $passed = $status === 403;
        $this->record('privilege_escalation_admin_route', ThreatActor::STAFF_INSIDER, 'A01', 'POST /documents/{code}/freeze', 'HTTP 403', 'HTTP ' . $status, $passed);
        $this->assertSame(403, $status, "FINDING [privilege_escalation_admin_route]: staff reached the freeze endpoint (HTTP {$status}).");
    }

    /**
     * A staff member must not view a document whose route does not include their department.
     * PREDICTED FINDING: DocumentController::show never calls DocumentPolicy::view.
     */
    public function test_staff_cannot_view_document_outside_their_route(): void
    {
        // Document routed only through the Personnel Unit, with a distinctive submitter name
        $document = Fixtures::submit(['guest_name' => 'Outside Route Person']);
        Fixtures::advanceTo($document['id'], 'in_transit', ['Personnel Unit']);

        // Cash staff (not on the route) requests the detail page
        $staff = HttpClient::start();
        $staff->loginAs('cash.unit');
        $response = $staff->get('/documents/' . $document['tracking_code']);

        // Denied would be a 403/redirect, or at least not leaking the document's contents
        $leaked = $response['status'] === 200 && str_contains($response['body'], 'Outside Route Person');
        $observed = sprintf('HTTP %d, submitter name %s', $response['status'], $leaked ? 'DISCLOSED' : 'not disclosed');

        $this->record('idor_view_outside_route', ThreatActor::STAFF_INSIDER, 'A01', 'GET /documents/{code}', 'denied (DocumentPolicy::view enforced)', $observed, !$leaked);
        $this->assertFalse($leaked, 'FINDING [idor_view_outside_route]: a document outside the staff route was fully disclosed. ' . $observed);
    }

    /**
     * Assert a stored-XSS payload is absent raw and present only in its escaped form, and record it.
     */
    private function assertXssEscaped(string $surface, string $payload, string $escaped, string $body): void
    {
        $rawAbsent = !str_contains($body, $payload);
        $escapedPresent = str_contains($body, $escaped);
        $passed = $rawAbsent && $escapedPresent;

        $observed = sprintf('raw %s, escaped %s', $rawAbsent ? 'absent' : 'PRESENT', $escapedPresent ? 'present' : 'absent');
        $this->record('stored_xss', ThreatActor::KIOSK_WALKUP, 'A03', $surface . ' <= ' . $payload, 'raw absent, htmlspecialchars form present', $observed, $passed);

        $this->assertTrue($rawAbsent, "FINDING [stored_xss]: raw payload rendered unescaped on {$surface}: {$payload}");
        $this->assertTrue($escapedPresent, "FINDING [stored_xss]: escaped payload not found on {$surface} (payload may not render there): {$payload}");
    }

    /**
     * Pull the first csrf token out of a rendered page (meta tag or hidden input).
     */
    private function extractToken(string $body): string
    {
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $body, $m)
            || preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Pull the console.error(...) message the app layout emits for a flashed console_error.
     */
    private function consoleError(string $body): string
    {
        if (preg_match('/console\.error\((".*?")\);/s', $body, $m)) {
            return (string) json_decode($m[1]);
        }

        return '';
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
