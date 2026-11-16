<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * A redirect during in-app navigation (a request with "X-PJAX: true") is never followed by fetch(): the
 * response carries no Location header but an X-PJAX-Location header, so the page the redirect leads to is
 * not rendered inside fetch() and the flash message it shows is not consumed there.
 */
class PjaxRedirectTest extends TestCase
{
    // GET a page the way the in-app navigation script does (X-PJAX header), then stop sending the header
    private function pjaxGet(HttpClient $client, string $path): array
    {
        $client->withHeader('X-PJAX', 'true');
        $response = $client->get($path);
        $client->withHeader('X-PJAX', null);

        return $response;
    }

    // A redirect with a flash message: the target path travels in a header, the flash survives for a real page load
    public function test_redirect_with_a_flash_keeps_the_flash_for_the_next_page_load(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');

        $response = $this->pjaxGet($client, '/users/999999/edit');

        $this->assertSame(204, $response['status']);
        $this->assertArrayNotHasKey('location', $response['headers']);
        $this->assertSame('/users', $response['headers']['x-pjax-location']);
        $this->assertStringContainsString('User not found.', $client->get('/users')['body']);
    }

    // Without the header the same request is a normal redirect
    public function test_plain_request_gets_a_normal_redirect(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');

        $response = $client->get('/users/999999/edit');

        $this->assertSame(302, $response['status']);
        $this->assertSame('/users', $response['headers']['location']);
        $this->assertArrayNotHasKey('x-pjax-location', $response['headers']);
    }

    // A guest's tracking request without a code redirects with an information message, kept the same way
    public function test_guest_tracking_redirect_keeps_its_message(): void
    {
        $client = HttpClient::start();
        $client->get('/');

        $response = $this->pjaxGet($client, '/track');

        $this->assertSame(204, $response['status']);
        $this->assertArrayNotHasKey('location', $response['headers']);
        $this->assertSame('/', $response['headers']['x-pjax-location']);
        $this->assertStringContainsString('Please enter a tracking code to view its status.', $client->get('/')['body']);
    }

    // A redirect without a flash message is announced the same way
    public function test_redirect_without_a_flash_is_announced_too(): void
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');

        $response = $this->pjaxGet($client, '/dashboard');

        $this->assertSame(204, $response['status']);
        $this->assertSame('/tasks', $response['headers']['x-pjax-location']);
    }

    // The Location value is reduced to a local path: an absolute same-host Referer is announced as a path only
    public function test_absolute_same_host_target_is_announced_as_a_path_only(): void
    {
        $client = HttpClient::start();
        $client->withHeader('Referer', $client->baseUrl() . '/login?from=test');

        $response = $this->pjaxGet($client, '/__test/throw');

        $this->assertSame(204, $response['status']);
        $this->assertSame('/login?from=test', $response['headers']['x-pjax-location']);
        $this->assertStringContainsString('X-PJAX', $response['headers']['vary']);
    }

    // An off-site or malformed target is not rewritten: the normal redirect stays (and the script never follows it)
    public function test_foreign_or_malformed_targets_are_left_as_redirects(): void
    {
        foreach (['https://evil.example/x', '//evil.example', '/\\evil', "/\t/evil.example"] as $target) {
            $client = HttpClient::start();

            $response = $this->pjaxGet($client, '/__test/redirect?to=' . rawurlencode($target));

            $this->assertSame(302, $response['status'], $target);
            $this->assertArrayNotHasKey('x-pjax-location', $response['headers'], $target);
        }
    }

    // A page that does not redirect is served as usual
    public function test_page_without_a_redirect_is_served_normally(): void
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');

        $response = $this->pjaxGet($client, '/tasks');

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('x-pjax-location', $response['headers']);
        $this->assertStringContainsString('</html>', $response['body']);
    }
}
