<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * The released-documents report stores only its whitelisted filters and downloads guest text as text.
 */
class ReportFiltersTest extends TestCase
{
    // Sign in as the Records Officer on the statistics page so the CSRF token is current
    private function officer(): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $client->get('/statistics');

        return $client;
    }

    // A report request stores the search filter only: no CSRF token and no unknown fields
    public function test_stored_report_filters_contain_no_csrf_token(): void
    {
        $response = $this->officer()->post('/statistics/report', ['search' => 'abc', 'unknown_field' => 'x']);
        $this->assertSame(200, $response['status']);

        // The report_jobs row and the queued job both carry the sanitized filters
        $db = Database::getInstance();
        $job = $db->query('SELECT filters FROM report_jobs ORDER BY created_at DESC LIMIT 1')->fetch();
        $this->assertSame(['search' => 'abc'], json_decode($job['filters'], true));
        $queued = $db->query('SELECT payload FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame(['search' => 'abc'], json_decode($queued['payload'], true)['data'][2]);
    }

    // A report with no filters is still accepted
    public function test_report_without_filters_is_accepted(): void
    {
        $response = $this->officer()->post('/statistics/report', []);
        $this->assertSame(200, $response['status']);

        $job = Database::getInstance()->query('SELECT filters FROM report_jobs ORDER BY created_at DESC LIMIT 1')->fetch();
        $this->assertSame([], json_decode($job['filters'], true));
    }

    // A released document whose title looks like a formula is downloaded as text, with no formula cell
    public function test_downloaded_report_contains_formula_like_title_as_text(): void
    {
        // Release one document titled like a formula, then register a completed report job for the officer
        $document = Fixtures::submit(['title' => '=1+1']);
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO report_jobs (id, user_id, status, progress, total_documents, filters, created_at, updated_at)
             VALUES ('formulatest1', :uid, 'completed', 100, 1, '[]', DATE_ADD(NOW(), INTERVAL 1 MINUTE), NOW())",
            [':uid' => $this->fx['users']['records.unit']]
        );

        // Download the spreadsheet and read its worksheet XML
        $response = $this->officer()->get('/statistics/report/download/formulatest1');
        $this->assertSame(200, $response['status']);
        $file = tempnam(sys_get_temp_dir(), 'dts-report-');
        file_put_contents($file, $response['body']);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($file);

        // The title is present as text and the sheet has no formula element
        $this->assertStringContainsString('=1+1', $sheet);
        $this->assertStringContainsString($document['tracking_code'], $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
    }
}
