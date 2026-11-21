<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Modules\System\BackupService;
use Tests\Support\AssertsSecurityHeaders;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Every response has the right Content-Type for what it sends. X-Content-Type-Options: nosniff (S9b) makes the
 * browser obey the declared type, so JSON sent as text/html, or a page sent as something else, would stop working.
 *
 * The endpoint list is read from public/index.php, so a new route is covered without editing this test.
 */
class ContentTypeTest extends TestCase
{
    use AssertsSecurityHeaders;

    private const HTML = 'text/html; charset=UTF-8';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    // Roles the sweep signs in as: a guest sees the public side, the others each see their own pages and the 403 pages
    private const ROLES = ['guest', 'admin', 'records', 'cash'];

    // Every route of the front controller as [method, path pattern]; PUT is the same handler as the POST route
    private function routes(): array
    {
        $index = (string) file_get_contents(BASE_PATH . '/public/index.php');
        // Every registration: get('path'), post('path') or add('METHOD', 'path'), with either quote style
        $found = preg_match_all('/\$router->(get|post|add)\(\s*(?:([\'"])([A-Z]+)\2\s*,\s*)?([\'"])(.+?)\4/', $index, $matches, PREG_SET_ORDER);

        // A route the pattern missed must fail the test, not slip through: compare with the number of registration calls
        $this->assertSame(preg_match_all('/\$router->(get|post|add)\(/', $index), $found, 'every route registration of public/index.php was extracted');

        // Method names to upper case, one entry per method and path
        $routes = [];
        foreach ($matches as $match) {
            $method = $match[1] === 'add' ? $match[3] : strtoupper($match[1]);
            $routes[$method . ' ' . $match[5]] = [$method, $match[5]];
        }

        return array_values($routes);
    }

    // A concrete request path for a route pattern: ids that match no record, the tracking code of a real document
    private function concretePath(string $pattern, string $trackingCode): string
    {
        $samples = ['id' => '999999', 'tracking_code' => $trackingCode, 'jobId' => 'nojob', 'fileName' => 'missing.sql.zip'];

        return (string) preg_replace_callback('/\(\?P<(\w+)>[^)]*\)/', fn (array $m) => $samples[$m[1]] ?? 'x', $pattern);
    }

    // A client signed in as the role (a guest just loads the start page) with an HTML page loaded for the CSRF token
    private function clientFor(string $role): HttpClient
    {
        $client = HttpClient::startWithoutRateLimit();
        if ($role === 'guest') {
            $client->get('/login');
            return $client;
        }
        $client->loginAs($role);
        $client->get('/tasks');

        return $client;
    }

    // What a body is, judged from the body itself: json, html, pdf, zip, csv or text
    private function bodyKind(string $body): string
    {
        $trimmed = ltrim($body);
        if (str_starts_with($body, '%PDF')) {
            return 'pdf';
        }
        if (str_starts_with($body, 'PK')) {
            return 'zip';
        }
        if (str_starts_with($body, 'id,connections')) {
            return 'csv';
        }
        // Valid JSON of any kind (an object, a list, or a bare false/null from a lookup that found nothing)
        if (json_validate($trimmed)) {
            return 'json';
        }

        return str_starts_with($trimmed, '<') ? 'html' : 'text';
    }

    // The declared type must match what the body is
    private function assertTypeMatchesBody(array $response, string $what): void
    {
        $body = $response['body'];
        $type = $response['headers']['content-type'] ?? '';
        $kind = $this->bodyKind($body);

        // Empty answers (redirects, 204) carry no content that could be misread
        if ($body === '') {
            return;
        }

        $this->assertNotSame('text', $kind, "{$what}: a body that is neither JSON nor HTML nor a file (type {$type}): " . substr($body, 0, 60));
        $expected = ['json' => 'application/json', 'html' => self::HTML, 'pdf' => 'application/pdf', 'csv' => 'text/csv'][$kind] ?? null;
        if ($expected !== null) {
            $this->assertStringStartsWith($expected, $type, "{$what}: {$kind} body sent as {$type}");
        }
        if ($kind === 'html') {
            $this->assertSame(self::HTML, $type, "{$what}: pages are text/html; charset=UTF-8");
        }
        if ($kind === 'zip') {
            $this->assertContains($type, ['application/zip', self::XLSX], "{$what}: archive sent as {$type}");
        }
        // Files are saved or shown by name
        if (in_array($kind, ['pdf', 'zip', 'csv'], true)) {
            $this->assertNotEmpty($response['headers']['content-disposition'] ?? '', "{$what}: file without Content-Disposition");
        }
    }

    // Every route, as every role, plain and as a script (XHR) request: the type matches the body and the security headers are there
    public function test_every_route_answers_with_a_type_that_matches_its_body(): void
    {
        $trackingCode = Fixtures::submit()['tracking_code'];
        $routes = $this->routes();
        $this->assertGreaterThan(60, count($routes), 'the route list was read from public/index.php');

        $checked = 0;
        $serverErrors = [];
        foreach (self::ROLES as $role) {
            $client = $this->clientFor($role);
            // The session's CSRF token, so POSTs reach their handlers instead of the 419 page
            $page = $client->get($role === 'guest' ? '/login' : '/tasks')['body'];
            preg_match('/<meta name="csrf-token" content="([^"]+)"|name="csrf_token" value="([^"]+)"/', $page, $token);
            $csrfToken = ($token[1] ?? '') !== '' ? $token[1] : ($token[2] ?? '');
            foreach ($routes as [$method, $pattern]) {
                // Signing out ends the session the rest of the sweep needs; the /__test/ probes exist to fail on purpose
                // (PUT has no client method here and runs the same handler as the POST route of the same path)
                if ($pattern === '/logout' || $method === 'PUT' || str_starts_with($pattern, '/__test/')) {
                    continue;
                }
                $path = $this->concretePath($pattern, $trackingCode);

                // A plain request and the same request as a script (fetch/XHR) sends it
                foreach ([false, true] as $asScript) {
                    $client->withHeader('X-Requested-With', $asScript ? 'XMLHttpRequest' : null);
                    $response = match ($method) {
                        'GET' => $client->get($path),
                        'DELETE' => $client->delete($path),
                        default => $client->post($path, ['csrf_token' => $csrfToken]),
                    };
                    $what = "{$role} " . ($asScript ? 'XHR ' : '') . "{$method} {$path}";
                    $this->assertSecurityHeaders($response, $what);
                    $checked++;

                    // A server error prints PHP's HTML-formatted warnings before the JSON while APP_DEBUG is on (never in production):
                    // those are listed below instead of type-checked
                    if ($response['status'] >= 500) {
                        $serverErrors[] = $what;
                        continue;
                    }
                    $this->assertTypeMatchesBody($response, $what);
                }
            }
        }
        $this->assertGreaterThan(500, $checked);

        // No route answers a server error for any role, plain or as a script request
        $this->assertSame([], $serverErrors, 'routes that answered 5xx');
    }

    // The JSON endpoints answer application/json for their success AND their error answers
    public function test_json_endpoints_answer_json_in_every_state(): void
    {
        // Signed out: the notification endpoints refuse in JSON (401), not with an HTML page
        $guest = HttpClient::startWithoutRateLimit();
        $guest->get('/login');
        $unread = $guest->get('/api/notifications/unread');
        $guest->get('/login');
        $markRead = $guest->post('/api/notifications/mark-read', []);
        foreach (['GET /api/notifications/unread' => $unread, 'POST /api/notifications/mark-read' => $markRead] as $what => $response) {
            $this->assertSame(401, $response['status'], $what);
            $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '', "{$what} (401)");
        }

        // Signed in: the success answers
        $admin = $this->clientFor('admin');
        foreach (['/api/notifications/unread', '/api/admin-dashboard/current-load', '/api/system-health/db-performance', '/system-health/results'] as $path) {
            $response = $admin->get($path);
            $this->assertSame(200, $response['status'], $path);
            $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '', $path);
        }

        // A script request that fails (an unknown route, a refused POST) also gets JSON, not an HTML page
        $admin->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->assertStringStartsWith('application/json', $admin->get('/this-page-does-not-exist')['headers']['content-type'] ?? '');
        $this->assertStringStartsWith('application/json', $admin->post('/api/notifications/mark-read', [], false)['headers']['content-type'] ?? '');
    }

    // The pages are HTML in UTF-8: the start page, an app page, the error pages and the HTML fragment the tracking page loads
    public function test_pages_are_html_in_utf8(): void
    {
        $document = Fixtures::submit();
        $admin = $this->clientFor('admin');
        $guest = HttpClient::startWithoutRateLimit();

        $responses = [
            'start page' => $guest->get('/'),
            'login' => $guest->get('/login'),
            '404' => $guest->get('/this-page-does-not-exist'),
            'tracking fragment' => $guest->get('/api/track/module/' . $document['tracking_code']),
            'app page' => $admin->get('/admin-dashboard'),
        ];
        foreach ($responses as $what => $response) {
            $this->assertSame(self::HTML, $response['headers']['content-type'] ?? '', $what);
        }
    }

    // The tracking form PDF, a backup, a report and the metrics export have their own type and a file name
    public function test_downloads_have_their_specific_type_and_a_content_disposition(): void
    {
        // The tracking form (shown in the browser)
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $officer = $this->clientFor('records');
        $pdf = $officer->get('/documents/' . $document['tracking_code'] . '/print-tracking-form');
        $this->assertSame('application/pdf', $pdf['headers']['content-type'] ?? '');
        $this->assertStringStartsWith('inline; filename=', $pdf['headers']['content-disposition'] ?? '');

        // A report spreadsheet
        Database::getInstance()->query(
            "INSERT INTO report_jobs (id, user_id, status, progress, total_documents, filters, created_at, updated_at)
             VALUES ('typejob1', :uid, 'completed', 100, 1, '[]', DATE_ADD(NOW(), INTERVAL 1 MINUTE), NOW())",
            [':uid' => $this->fx['users']['records']]
        );
        $officer->get('/statistics');
        $report = $officer->get('/statistics/report/download/typejob1');
        $this->assertSame(self::XLSX, $report['headers']['content-type'] ?? '');
        $this->assertStringStartsWith('attachment; filename="released-documents-', $report['headers']['content-disposition'] ?? '');

        // A backup archive
        $directory = BackupService::directory();
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        file_put_contents("{$directory}/type-test.sql", "SELECT 1;\n");
        BackupService::archive("{$directory}/type-test.sql", "{$directory}/type-test.sql.zip");
        unlink("{$directory}/type-test.sql");
        $admin = $this->clientFor('admin');
        $admin->get('/system/backups');
        $backup = $admin->post('/system/backups/download/type-test.sql.zip', ['pin' => TestDatabase::PIN]);
        $this->assertSame('application/zip', $backup['headers']['content-type'] ?? '');
        $this->assertSame('attachment; filename="type-test.sql.zip"', $backup['headers']['content-disposition'] ?? '');

        // The database metrics export
        Database::getInstance()->query("INSERT INTO database_metrics (connections, avg_query_time_ms, slow_queries, created_at) VALUES (1, 1.5, 0, NOW())");
        $csv = $admin->get('/admin/system-health/export-db-metrics');
        $this->assertStringStartsWith('text/csv', $csv['headers']['content-type'] ?? '');
        $this->assertStringStartsWith('attachment; filename="database-performance-metrics-', $csv['headers']['content-disposition'] ?? '');
    }
}
