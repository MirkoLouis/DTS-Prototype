<?php

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\TestCase;

class TrackingFormPrintTest extends TestCase
{
    // Officer's manage page links to the print form
    public function test_officer_manage_page_links_print_form(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $response = $client->get('/documents/' . $doc['id'] . '/manage');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('/documents/' . $doc['tracking_code'] . '/print-tracking-form', $response['body']);
    }

    // Officer's document detail page links to the print form
    public function test_officer_document_page_links_print_form(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $response = $client->get('/documents/' . $doc['tracking_code']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('/documents/' . $doc['tracking_code'] . '/print-tracking-form', $response['body']);
    }

    // Staff never see the print button
    public function test_staff_document_page_has_no_print_button(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Cash Unit']);
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $response = $client->get('/documents/' . $doc['tracking_code']);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('print-tracking-form', $response['body']);
    }

    // Submit a document through the guest form with the given browser session and return its tracking code
    private function submitAsGuest(HttpClient $client): string
    {
        $client->get('/');
        $response = $client->post('/submit-document', [
            'guest_name' => 'Print Rule Guest',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '09171234567',
            'district' => 'N/A',
            'department' => 'Records Unit',
            'title' => 'Print rule document',
            'purpose_id' => (string) reset($this->fx['purposes']),
        ]);
        $this->assertSame(302, $response['status']);
        parse_str((string) parse_url($response['headers']['location'] ?? '', PHP_URL_QUERY), $query);

        return (string) ($query['tracking_code'] ?? '');
    }

    // Assert a response is the tracking-form PDF
    private function assertPdf(array $response): void
    {
        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('%PDF', $response['body']);
    }

    // A guest prints the slip of the document they just submitted, in the same browser session, without signing in
    public function test_guest_prints_own_submission_in_same_session(): void
    {
        $client = HttpClient::start();
        $code = $this->submitAsGuest($client);
        $this->assertNotSame('', $code);

        $this->assertPdf($client->get("/documents/{$code}/print-tracking-form"));
    }

    // Someone who only knows a tracking code cannot print the slip, which carries the submitter's contact details
    public function test_anonymous_visitor_cannot_print_someone_elses_form(): void
    {
        $code = $this->submitAsGuest(HttpClient::start());
        $response = HttpClient::start()->get("/documents/{$code}/print-tracking-form");

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('Please sign in to print this tracking form.', $response['body']);
        $this->assertStringNotContainsString('%PDF', $response['body']);
    }

    // Staff cannot print the slip of a document that does not pass through their department
    public function test_staff_cannot_print_documents_outside_their_route(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Personnel Unit']);
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $response = $client->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form');

        $this->assertSame(302, $response['status']);
        $this->assertSame('/dashboard', $response['headers']['location'] ?? '');
        $this->assertStringNotContainsString('%PDF', $response['body']);
    }

    // Staff can print the slip of a document on their department's route
    public function test_staff_prints_documents_on_their_route(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Cash Unit']);
        $client = HttpClient::start();
        $client->loginAs('cash.unit');

        $this->assertPdf($client->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form'));
    }

    // The Records Officer can print any document's slip
    public function test_officer_prints_any_document(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');

        $this->assertPdf($client->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form'));
    }

    // A deactivated account's live session no longer counts as signed in on the print route
    public function test_deactivated_user_cannot_print(): void
    {
        // The officer is signed in, then deactivated while the session is still open
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        \App\Common\Database\Database::getInstance()->query(
            'UPDATE users SET deactivated_at = NOW() WHERE id = :id',
            [':id' => $this->fx['users']['records.unit']]
        );

        // The print route has no AuthMiddleware, so it must refuse the deactivated account itself
        $response = $client->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form');
        $this->assertSame(403, $response['status']);
        $this->assertStringNotContainsString('%PDF', $response['body']);
    }

    // An unknown tracking code is still a 404 for a signed-in user
    public function test_unknown_code_is_not_found(): void
    {
        $client = HttpClient::start();
        $client->loginAs('records.unit');

        $this->assertSame(404, $client->get('/documents/DEPED-ZZZZZZZZZZ/print-tracking-form')['status']);
    }

    // Signing in clears the guest's submitted codes, so staff using the same browser get no access through them
    public function test_login_clears_the_guest_sessions_submitted_codes(): void
    {
        // A guest submits on a shared browser, then Cash Unit staff sign in on it
        $client = HttpClient::start();
        $code = $this->submitAsGuest($client);
        $client->loginAs('cash.unit');

        // The document is not on the Cash Unit's route, so the slip is refused like any other
        $response = $client->get("/documents/{$code}/print-tracking-form");
        $this->assertSame(302, $response['status']);
        $this->assertSame('/dashboard', $response['headers']['location'] ?? '');
        $this->assertStringNotContainsString('%PDF', $response['body']);
    }

    // A session remembers only its newest codes, so a shared kiosk session does not grow without limit
    public function test_submitted_codes_keep_only_the_newest_twenty(): void
    {
        $codes = [];
        for ($i = 1; $i <= 21; $i++) {
            $codes = \App\Modules\Documents\GuestController::withSubmittedCode($codes, "DEPED-{$i}");
        }

        $this->assertCount(20, $codes);
        $this->assertNotContains('DEPED-1', $codes);
        $this->assertSame('DEPED-21', end($codes));

        // A code already in the list is not stored twice
        $this->assertCount(20, \App\Modules\Documents\GuestController::withSubmittedCode($codes, 'DEPED-21'));
    }
}
