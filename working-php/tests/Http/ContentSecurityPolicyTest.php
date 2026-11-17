<?php

namespace Tests\Http;

use App\Common\Http\SecurityHeaders;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * The Content Security Policy is sent in report-only mode on every PHP response (S9a); it is not enforced yet.
 */
class ContentSecurityPolicyTest extends TestCase
{
    // The report-only header and its name
    private const REPORT_ONLY_HEADER = 'content-security-policy-report-only';

    // The policy exactly as agreed for this version (written out here on purpose: a change must be deliberate)
    private const EXPECTED_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    // The response carries the exact policy in report-only mode and no enforcing policy
    private function assertReportOnlyPolicy(array $response, string $what): void
    {
        $this->assertSame(self::EXPECTED_POLICY, $response['headers'][self::REPORT_ONLY_HEADER] ?? null, "{$what}: report-only policy");
        $this->assertArrayNotHasKey('content-security-policy', $response['headers'], "{$what}: the policy must not be enforced yet");
    }

    // The constants behind the header: one policy string, and the header name S9b switches
    public function test_policy_and_header_name_constants(): void
    {
        $this->assertSame(self::EXPECTED_POLICY, SecurityHeaders::POLICY);
        $this->assertSame('Content-Security-Policy-Report-Only', SecurityHeaders::HEADER_NAME);
    }

    // A normal page
    public function test_normal_page_has_the_report_only_policy(): void
    {
        $this->assertReportOnlyPolicy(HttpClient::start()->get('/'), 'guest page');

        $client = HttpClient::start();
        $client->loginAs('admin');
        $this->assertReportOnlyPolicy($client->get('/admin-dashboard'), 'app page');
    }

    // A JSON API response
    public function test_json_api_response_has_the_report_only_policy(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $response = $client->get('/api/notifications/unread');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');
        $this->assertReportOnlyPolicy($response, 'JSON API');
    }

    // A redirect
    public function test_redirect_has_the_report_only_policy(): void
    {
        $response = HttpClient::start()->get('/system/backups');

        $this->assertContains($response['status'], [301, 302, 303]);
        $this->assertNotEmpty($response['headers']['location'] ?? '');
        $this->assertReportOnlyPolicy($response, 'redirect');
    }

    // The in-app navigation answer to a redirect: 204 with X-PJAX-Location instead of Location (headers rewritten at flush time)
    public function test_pjax_redirect_answer_has_the_report_only_policy(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->withHeader('X-PJAX', 'true');
        $response = $client->get('/users/999999/edit');

        $this->assertSame(204, $response['status']);
        $this->assertSame('/users', $response['headers']['x-pjax-location'] ?? null);
        $this->assertReportOnlyPolicy($response, 'PJAX 204');
    }

    // A refused POST (419: missing CSRF token)
    public function test_419_response_has_the_report_only_policy(): void
    {
        $guest = HttpClient::start();
        $guest->get('/');
        $response = $guest->post('/submit-document', ['guest_name' => 'x'], false);

        $this->assertSame(419, $response['status']);
        $this->assertReportOnlyPolicy($response, '419 page');
    }

    // An error page
    public function test_error_page_has_the_report_only_policy(): void
    {
        $response = HttpClient::start()->get('/this-page-does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertReportOnlyPolicy($response, '404 page');
    }

    // A file download (the tracking form PDF)
    public function test_file_download_has_the_report_only_policy(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $response = $client->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('%PDF', $response['body']);
        $this->assertReportOnlyPolicy($response, 'PDF download');
    }

    // The header is sent from ONE place in PHP, before routing
    public function test_header_is_sent_from_the_front_controller_only(): void
    {
        $index = (string) file_get_contents(BASE_PATH . '/public/index.php');
        $this->assertSame(1, substr_count($index, 'SecurityHeaders::send()'));
        $this->assertLessThan(strpos($index, 'new Router()'), strpos($index, 'SecurityHeaders::send()'), 'sent before routing');

        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            // Only the class itself names the header
            if (stripos($source, 'Content-Security-Policy') !== false && !str_ends_with($file->getPathname(), 'SecurityHeaders.php')) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders);
    }
}
