<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * Common HTTP errors are answered with a styled page (or JSON for scripts) that shows the status, says what
 * happened and offers a way back; it never shows exception text, file paths or stack traces.
 */
class ErrorPageTest extends TestCase
{
    // Ask for JSON the way a script does
    private function jsonClient(?string $username = null): HttpClient
    {
        $client = HttpClient::start();
        if ($username !== null) {
            $client->loginAs($username);
        }
        $client->withHeader('Accept', 'application/json');

        return $client;
    }

    // An unknown route is a 404 page for a guest, with a way back to the start page
    public function test_unknown_route_shows_the_404_page_to_a_guest(): void
    {
        $response = HttpClient::start()->get('/no-such-page');

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('404', $response['body']);
        $this->assertStringContainsString('Not Found', $response['body']);
        $this->assertStringContainsString('href="/"', $response['body']);
        $this->assertStringContainsString('</html>', $response['body']);
    }

    // For a signed-in user the way back is the home page of their role
    public function test_unknown_route_for_signed_in_users_links_to_their_home(): void
    {
        foreach (['admin' => '/admin-dashboard', 'records.unit' => '/intake', 'cash.unit' => '/tasks'] as $username => $home) {
            $client = HttpClient::start();
            $client->loginAs($username);

            $response = $client->get('/no-such-page');

            $this->assertSame(404, $response['status'], $username);
            $this->assertStringContainsString('href="' . $home . '"', $response['body'], $username);
        }
    }

    // A role violation is a 403 page
    public function test_wrong_role_shows_the_403_page(): void
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');

        $response = $client->get('/users');

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('Forbidden', $response['body']);
        $this->assertStringContainsString('href="/tasks"', $response['body']);
    }

    // A POST without a CSRF token is a 419 page that tells the person to reload
    public function test_missing_csrf_token_shows_the_419_page(): void
    {
        $client = HttpClient::start();
        $client->get('/');

        $response = $client->post('/submit-document', ['guest_name' => 'x'], false);

        $this->assertSame(419, $response['status']);
        $this->assertStringContainsString('Page Expired', $response['body']);
        $this->assertStringContainsString('Reload the page', $response['body']);
    }

    // Scripts get the same statuses with a JSON body
    public function test_scripts_get_json_with_the_same_status(): void
    {
        $cases = [
            [$this->jsonClient()->get('/no-such-page'), 404],
            [$this->jsonClient('cash.unit')->get('/users'), 403],
        ];
        $client = $this->jsonClient();
        $client->get('/');
        $cases[] = [$client->post('/submit-document', ['guest_name' => 'x'], false), 419];

        foreach ($cases as [$response, $status]) {
            $this->assertSame($status, $response['status']);
            $this->assertStringContainsString('application/json', $response['headers']['content-type']);
            $body = json_decode($response['body'], true);
            $this->assertFalse($body['success']);
            $this->assertNotSame('', $body['message']);
        }
    }

    // A server error shows the 500 page with no exception text, file path or stack trace
    public function test_500_page_shows_no_internal_details(): void
    {
        $response = HttpClient::start()->get('/__test/error?status=500');

        $this->assertSame(500, $response['status']);
        $this->assertStringContainsString('Internal Server Error', $response['body']);
        foreach (['Stack trace', BASE_PATH, '.php', 'Exception'] as $internal) {
            $this->assertStringNotContainsString($internal, $response['body']);
        }
    }

    // A real exception in a GET request with nowhere safe to go back to shows the 500 page without any internals
    public function test_real_exception_without_a_referer_shows_the_500_page_without_internals(): void
    {
        $response = HttpClient::start()->get('/__test/throw');

        $this->assertSame(500, $response['status']);
        $this->assertArrayNotHasKey('location', $response['headers']);
        $this->assertStringContainsString('Internal Server Error', $response['body']);
        foreach (['secret-detail', 'Secret.php', 'RuntimeException', 'Router.php', 'Stack trace', BASE_PATH] as $internal) {
            $this->assertStringNotContainsString($internal, $response['body']);
        }
    }

    // A failing GET goes back to a same-host Referer (as a path), with the flash message
    public function test_failing_get_goes_back_to_a_same_host_referer(): void
    {
        $client = HttpClient::start();
        $client->withHeader('Referer', $client->baseUrl() . '/login?from=test');

        $response = $client->get('/__test/throw');
        $client->withHeader('Referer', null);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/login?from=test', $response['headers']['location']);
        $this->assertStringContainsString('An unexpected error occurred', $client->get('/login')['body']);
    }

    // A failing GET whose Referer is the failing page itself would loop: the 500 page is shown
    public function test_failing_get_with_the_failing_page_as_referer_shows_the_500_page(): void
    {
        $client = HttpClient::start();
        $client->withHeader('Referer', $client->baseUrl() . '/__test/throw');

        $response = $client->get('/__test/throw');

        $this->assertSame(500, $response['status']);
        $this->assertStringNotContainsString('secret-detail', $response['body']);
    }

    // A Referer on another site is never followed: no redirect off-site
    public function test_foreign_referer_is_not_followed(): void
    {
        foreach (['https://evil.example/x', '//evil.example', "/\t/evil.example"] as $referer) {
            $client = HttpClient::start();
            $client->withHeader('Referer', $referer);

            $response = $client->get('/__test/throw');

            $this->assertArrayNotHasKey('location', $response['headers'], $referer);
            $this->assertSame(500, $response['status'], $referer);
        }
    }

    // A failing POST can always go back (a redirect after a POST cannot loop), even to the page that holds the form
    public function test_failing_post_redirects_back_with_the_flash(): void
    {
        $client = HttpClient::start();
        $client->get('/login');

        $response = $client->post('/__test/throw', [], true, '/__test/throw');

        $this->assertSame(302, $response['status']);
        $this->assertSame('/__test/throw', $response['headers']['location']);
        $this->assertStringContainsString('An unexpected error occurred', $client->get('/login')['body']);

        // With a foreign or missing Referer it goes to the start page
        $client->get('/login');
        $client->withHeader('Referer', 'https://evil.example/x');
        $this->assertSame('/', $client->post('/__test/throw', [])['headers']['location']);
    }

    // Output a page produced before it failed is discarded, so it is not followed by the error page, and never cached
    public function test_partial_output_is_discarded_and_an_error_is_not_cached(): void
    {
        foreach (['/__test/partial-error', '/__test/partial-error-cached'] as $path) {
            $client = HttpClient::start();
            for ($i = 0; $i < 2; $i++) {
                $response = $client->get($path);

                $this->assertSame(500, $response['status'], $path);
                $this->assertStringNotContainsString('PARTIAL-OUTPUT', $response['body'], $path);
                $this->assertStringContainsString('Internal Server Error', $response['body'], $path);
            }
        }
    }

    // The status table covers 403, 404, 405, 419, 429, 500 and 503; any other status gets a generic page
    public function test_status_table_and_generic_fallback(): void
    {
        $titles = [403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 419 => 'Page Expired',
            429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable'];
        foreach ($titles as $status => $title) {
            $response = HttpClient::start()->get('/__test/error?status=' . $status);
            $this->assertSame($status, $response['status']);
            $this->assertStringContainsString($title, $response['body'], (string) $status);
        }

        $generic = HttpClient::start()->get('/__test/error?status=418');
        $this->assertSame(418, $generic['status']);
        $this->assertStringContainsString('418', $generic['body']);
        $this->assertStringContainsString('Something went wrong', $generic['body']);
    }

    // A 429 sends Retry-After when given a number of seconds
    public function test_429_sends_retry_after(): void
    {
        $response = HttpClient::start()->get('/__test/error?status=429&retry=30');

        $this->assertSame(429, $response['status']);
        $this->assertSame('30', $response['headers']['retry-after']);
        $this->assertArrayNotHasKey('retry-after', HttpClient::start()->get('/__test/error?status=429')['headers']);
    }

    // A custom message replaces the default sentence and is escaped
    public function test_message_is_shown_and_escaped(): void
    {
        $response = HttpClient::start()->get('/__test/error?status=404&message=' . urlencode('<script>alert(1)</script> & more'));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $response['body']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; more', $response['body']);
    }

    // The JSON form carries the message too
    public function test_json_form_of_the_helper(): void
    {
        $client = $this->jsonClient();

        $response = $client->get('/__test/error?status=503&retry=5&message=Back+soon');

        $this->assertSame(503, $response['status']);
        $this->assertSame(['success' => false, 'message' => 'Back soon'], json_decode($response['body'], true));
        $this->assertSame('5', $response['headers']['retry-after']);
    }

    // A document that does not exist is a 404 page, and the sign-in hint for printing keeps its wording
    public function test_document_errors_use_the_page(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $missing = $client->get('/documents/DEPED-ZZZZZZZZZZ');
        $this->assertSame(404, $missing['status']);
        $this->assertStringContainsString('Document not found.', $missing['body']);
        $this->assertStringContainsString('Not Found', $missing['body']);

        $guest = HttpClient::start()->get('/documents/DEPED-ZZZZZZZZZZ/print-tracking-form');
        $this->assertSame(403, $guest['status']);
        $this->assertStringContainsString('Please sign in to print this tracking form.', $guest['body']);
        $this->assertStringContainsString('Forbidden', $guest['body']);
    }
}
