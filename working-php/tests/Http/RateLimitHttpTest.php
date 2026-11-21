<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * Per-IP rate limits on the public routes, seen over HTTP (the test server runs with the limiter on).
 */
class RateLimitHttpTest extends TestCase
{
    // Tracking code that exists in no database: a guest's print request is answered 403 before any PDF is built
    private const UNKNOWN_CODE = 'DEPED-ZZZZZZZZZZ';

    // Form fields of a valid guest submission
    private function submissionFields(): array
    {
        return [
            'guest_name' => 'Rate Limit Guest',
            'guest_email' => 'rate@example.com',
            'guest_phone' => '09171234567',
            'district' => 'N/A',
            'department' => 'Records Unit',
            'title' => 'Rate limit check',
            'purpose_id' => (string) reset($this->fx['purposes']),
        ];
    }

    // Number of stored documents
    private function documentCount(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents')->fetch()['c'];
    }

    // Send the print request for an unknown code $times times and return the last response
    private function printRequests(HttpClient $client, int $times): array
    {
        $response = [];
        for ($i = 0; $i < $times; $i++) {
            $response = $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form');
        }

        return $response;
    }

    // The 11th submission within a minute is refused with 429, Retry-After and the styled page, and creates no document
    public function test_eleventh_submission_is_refused_and_creates_no_document(): void
    {
        $client = HttpClient::start();

        // Ten submissions go through to the success page (each loads the form first, which carries the CSRF token)
        for ($i = 1; $i <= 10; $i++) {
            $client->get('/');
            $response = $client->post('/submit-document', $this->submissionFields());
            $this->assertSame(302, $response['status'], "submission {$i}");
            $this->assertStringContainsString('/success', $response['headers']['location'] ?? '');
        }
        $this->assertSame(10, $this->documentCount());

        // The eleventh gets the 429 page and no document is created
        $client->get('/');
        $response = $client->post('/submit-document', $this->submissionFields());
        $this->assertSame(429, $response['status']);
        $this->assertGreaterThanOrEqual(1, (int) ($response['headers']['retry-after'] ?? 0));
        $this->assertLessThanOrEqual(60, (int) $response['headers']['retry-after']);
        $this->assertStringContainsString('<html', strtolower($response['body']));
        $this->assertStringContainsString('Too Many Requests', $response['body']);
        $this->assertStringContainsString('requests came from your connection', $response['body']);
        $this->assertSame(10, $this->documentCount());
    }

    // The 11th print request within a minute is refused
    public function test_eleventh_print_request_is_refused(): void
    {
        $client = HttpClient::start();

        $this->assertSame(403, $this->printRequests($client, 10)['status']);
        $response = $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form');

        $this->assertSame(429, $response['status']);
        $this->assertArrayHasKey('retry-after', $response['headers']);
    }

    // The status poll over its limit gets JSON with status 429 and Retry-After
    public function test_status_poll_over_the_limit_gets_json_429(): void
    {
        $client = HttpClient::start();

        // 240 polls per minute are allowed
        for ($i = 1; $i <= 240; $i++) {
            $response = $client->get('/api/track/status?codes=' . self::UNKNOWN_CODE);
            $this->assertSame(200, $response['status'], "poll {$i}");
        }

        $response = $client->get('/api/track/status?codes=' . self::UNKNOWN_CODE);
        $this->assertSame(429, $response['status']);
        $this->assertStringContainsString('application/json', $response['headers']['content-type'] ?? '');
        $this->assertArrayHasKey('retry-after', $response['headers']);
        $body = json_decode($response['body'], true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('requests came from your connection', $body['message']);
    }

    // A limit reached on one bucket does not affect another
    public function test_one_bucket_does_not_affect_another(): void
    {
        $client = HttpClient::start();
        $this->printRequests($client, 11);

        $this->assertSame(429, $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form')['status']);
        $this->assertNotSame(429, $client->get('/track')['status']);
        $this->assertSame(200, $client->get('/api/track/status?codes=' . self::UNKNOWN_CODE)['status']);
    }

    // The limits apply to signed-in users too
    public function test_signed_in_users_are_limited_as_well(): void
    {
        $client = HttpClient::start();
        $client->loginAs('records');

        $this->printRequests($client, 10);
        $this->assertSame(429, $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form')['status']);
    }

    // A forwarded-for header does not move a request into another client's bucket
    public function test_forwarded_header_does_not_evade_the_limit(): void
    {
        $client = HttpClient::start();

        // The server really receives the header (the probe echoes it back)
        $client->withHeader('X-Forwarded-For', '198.51.100.200');
        $probe = json_decode($client->get('/__test/client-headers')['body'], true);
        $this->assertSame('198.51.100.200', $probe['x_forwarded_for']);

        // Every request names a different forwarded address; a limiter keyed on it would never refuse
        $statuses = [];
        for ($i = 1; $i <= 11; $i++) {
            $client->withHeader('X-Forwarded-For', "198.51.100.{$i}");
            $statuses[] = $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form')['status'];
        }
        $this->assertSame(429, $statuses[10]);
        $this->assertSame(array_fill(0, 10, 403), array_slice($statuses, 0, 10));
    }

    // Once the window has passed (the row aged in the test database) requests work again
    public function test_requests_work_again_after_the_window_has_passed(): void
    {
        $client = HttpClient::start();
        $this->printRequests($client, 11);
        $this->assertSame(429, $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form')['status']);

        // Age the counter so its window ended a minute ago
        Database::getInstance()->query('UPDATE rate_limits SET expires_at = expires_at - 120');

        $this->assertSame(403, $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form')['status']);
    }

    // With the rate_limits table missing (migration not run) the request still succeeds
    public function test_missing_table_fails_open(): void
    {
        Database::getInstance()->getConnection()->exec('DROP TABLE rate_limits');
        $client = HttpClient::start();

        // More requests than the limit all get their normal answer
        for ($i = 0; $i < 12; $i++) {
            $this->assertSame(403, $client->get('/documents/' . self::UNKNOWN_CODE . '/print-tracking-form')['status']);
        }
        $this->assertNotSame(429, $client->get('/track')['status']);
    }
}
