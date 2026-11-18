<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Common\Http\SecurityHeaders;
use App\Modules\System\BackupService;
use Tests\Support\AssertsSecurityHeaders;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Every PHP response carries the security headers, sent from one place (SecurityHeaders): the Content Security
 * Policy in ENFORCING mode (S9b), X-Frame-Options, X-Content-Type-Options and Referrer-Policy; no X-Powered-By.
 */
class SecurityHeadersTest extends TestCase
{
    use AssertsSecurityHeaders;

    // The constants behind the headers
    public function test_constants(): void
    {
        $this->assertSame(self::EXPECTED_POLICY, SecurityHeaders::POLICY);
        $this->assertSame('Content-Security-Policy', SecurityHeaders::HEADER_NAME);
        $this->assertSame(
            ['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy'],
            array_keys(SecurityHeaders::HEADERS)
        );
        $this->assertSame(['X-Powered-By'], SecurityHeaders::REMOVED_HEADERS);
    }

    // A normal page
    public function test_normal_page(): void
    {
        $this->assertSecurityHeaders(HttpClient::start()->get('/'), 'guest page');

        $client = HttpClient::start();
        $client->loginAs('admin');
        $this->assertSecurityHeaders($client->get('/admin-dashboard'), 'app page');
    }

    // A JSON API response
    public function test_json_api_response(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $response = $client->get('/api/notifications/unread');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');
        $this->assertSecurityHeaders($response, 'JSON API');
    }

    // A redirect
    public function test_redirect(): void
    {
        $response = HttpClient::start()->get('/system/backups');

        $this->assertContains($response['status'], [301, 302, 303]);
        $this->assertNotEmpty($response['headers']['location'] ?? '');
        $this->assertSecurityHeaders($response, 'redirect');
    }

    // The in-app navigation answer to a redirect: 204 with X-PJAX-Location instead of Location (headers rewritten at flush time)
    public function test_pjax_redirect_answer(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->withHeader('X-PJAX', 'true');
        $response = $client->get('/users/999999/edit');

        $this->assertSame(204, $response['status']);
        $this->assertSame('/users', $response['headers']['x-pjax-location'] ?? null);
        $this->assertSecurityHeaders($response, 'PJAX 204');
    }

    // A refused POST (419: missing CSRF token)
    public function test_419_response(): void
    {
        $guest = HttpClient::start();
        $guest->get('/');
        $response = $guest->post('/submit-document', ['guest_name' => 'x'], false);

        $this->assertSame(419, $response['status']);
        $this->assertSecurityHeaders($response, '419 page');
    }

    // An error page
    public function test_error_page(): void
    {
        $response = HttpClient::start()->get('/this-page-does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertSecurityHeaders($response, '404 page');
    }

    // The tracking form PDF
    public function test_pdf_download(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $response = $client->get('/documents/' . $doc['tracking_code'] . '/print-tracking-form');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('%PDF', $response['body']);
        $this->assertSecurityHeaders($response, 'PDF download');
    }

    // A backup download (the archive of the database)
    public function test_backup_download(): void
    {
        // One backup archive in an otherwise empty backup folder
        $directory = BackupService::directory();
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        file_put_contents("{$directory}/headers-test.sql", "SELECT 1;\n");
        BackupService::archive("{$directory}/headers-test.sql", "{$directory}/headers-test.sql.zip");
        unlink("{$directory}/headers-test.sql");

        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->get('/system/backups');
        $response = $client->post('/system/backups/download/headers-test.sql.zip', ['pin' => TestDatabase::PIN]);

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('PK', $response['body']);
        $this->assertSecurityHeaders($response, 'backup download');
    }

    // A report download (the released-documents spreadsheet)
    public function test_report_download(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        Database::getInstance()->query(
            "INSERT INTO report_jobs (id, user_id, status, progress, total_documents, filters, created_at, updated_at)
             VALUES ('headersjob1', :uid, 'completed', 100, 1, '[]', DATE_ADD(NOW(), INTERVAL 1 MINUTE), NOW())",
            [':uid' => $this->fx['users']['records.unit']]
        );

        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $client->get('/statistics');
        $response = $client->get('/statistics/report/download/headersjob1');

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('PK', $response['body']);
        $this->assertSecurityHeaders($response, 'report download');
    }

    // Same-origin navigation still sends the Referer (Referrer-Policy: same-origin), so a refused action returns to its page
    public function test_same_origin_referer_is_still_used_for_the_return_page(): void
    {
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $client->get('/intake');

        // A decline without a reason is refused and the redirect goes back to the page named by the Referer (not the default /intake)
        $response = $client->post('/documents/decline', ['document_id' => '999999'], true, '/releasing');
        $this->assertSame(302, $response['status']);
        $this->assertSame('/releasing', $response['headers']['location'] ?? null);
        $this->assertSecurityHeaders($response, 'form post');
    }

    // The policy leaves no way to post a form, frame a page or embed an object that the app itself uses
    public function test_no_view_uses_what_the_policy_forbids(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src/Views', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            // A frame, embed or object (object-src and frame-ancestors forbid them), or a form posting to another origin (form-action 'self')
            if (preg_match('/<(iframe|embed|object)\b|<form\b[^>]*\baction="https?:\/\/|<base\b/i', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders);
    }

    // The headers are sent from ONE place in PHP, before routing
    public function test_headers_are_sent_from_the_front_controller_only(): void
    {
        $index = (string) file_get_contents(BASE_PATH . '/public/index.php');
        $this->assertSame(1, substr_count($index, 'SecurityHeaders::send()'));
        $this->assertLessThan(strpos($index, 'new Router()'), strpos($index, 'SecurityHeaders::send()'), 'sent before routing');

        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            // Only the class itself names the policy; no other code sets one of these headers
            $setsHeader = preg_match('/header\(\s*[\'"](Content-Security-Policy|X-Frame-Options|X-Content-Type-Options|Referrer-Policy|Strict-Transport-Security)/i', $source) === 1;
            if (($setsHeader || stripos($source, 'Content-Security-Policy') !== false) && !str_ends_with($file->getPathname(), 'SecurityHeaders.php')) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders);
    }
}
