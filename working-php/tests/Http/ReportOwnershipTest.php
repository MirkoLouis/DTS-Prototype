<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * A report job can be downloaded, inspected and cancelled only by the user who requested it.
 */
class ReportOwnershipTest extends TestCase
{
    // Register a report job for the Records Officer in the given status and return its id
    private function officerJob(string $id, string $status): string
    {
        Database::getInstance()->query(
            "INSERT INTO report_jobs (id, user_id, status, progress, total_documents, filters, created_at, updated_at)
             VALUES (:id, :uid, :status, 0, 1, :filters, DATE_ADD(NOW(), INTERVAL 1 MINUTE), NOW())",
            [':id' => $id, ':uid' => $this->fx['users']['records.unit'], ':status' => $status, ':filters' => json_encode(['search' => 'secret-filter'])]
        );

        return $id;
    }

    // Sign in on the statistics page so the CSRF token is current
    private function signedIn(string $username): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs($username);
        $client->get('/statistics');

        return $client;
    }

    // Current status of a report job
    private function jobStatus(string $id): string
    {
        return Database::getInstance()->query('SELECT status FROM report_jobs WHERE id = :id', [':id' => $id])->fetch()['status'];
    }

    // Another user who knows the job id gets no spreadsheet
    public function test_another_user_cannot_download_the_report(): void
    {
        // A released document so the report would have content
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $id = $this->officerJob('ownedjob1', 'completed');

        $response = $this->signedIn('cash.unit')->get("/statistics/report/download/{$id}");
        $this->assertSame(302, $response['status']);
        $this->assertSame('/statistics', $response['headers']['location'] ?? '');
        $this->assertStringNotContainsString($document['tracking_code'], $response['body']);
    }

    // The owner still downloads the spreadsheet
    public function test_owner_downloads_the_report(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $id = $this->officerJob('ownedjob2', 'completed');

        $response = $this->signedIn('records.unit')->get("/statistics/report/download/{$id}");
        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('PK', $response['body']);
    }

    // Another user cannot read the job (its filters and state); the owner can
    public function test_report_status_is_visible_to_its_owner_only(): void
    {
        $id = $this->officerJob('ownedjob3', 'queued');

        $other = $this->signedIn('cash.unit')->get("/statistics/report/status?job_id={$id}");
        $this->assertSame(404, $other['status']);
        $this->assertStringNotContainsString('secret-filter', $other['body']);

        $own = $this->signedIn('records.unit')->get("/statistics/report/status?job_id={$id}");
        $this->assertSame(200, $own['status']);
        $this->assertSame('queued', json_decode($own['body'], true)['status'] ?? null);
    }

    // Another user cannot cancel the job; the owner can
    public function test_report_is_cancelled_by_its_owner_only(): void
    {
        $id = $this->officerJob('ownedjob4', 'queued');

        $this->signedIn('cash.unit')->post('/statistics/report/cancel', ['job_id' => $id]);
        $this->assertSame('queued', $this->jobStatus($id));

        $this->signedIn('records.unit')->post('/statistics/report/cancel', ['job_id' => $id]);
        $this->assertSame('cancelled', $this->jobStatus($id));
    }
}
