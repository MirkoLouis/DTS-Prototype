<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * Deleting failed jobs returns the admin to System Overview, where the failed-jobs table lives.
 */
class FailedJobDeleteTest extends TestCase
{
    // Insert one failed job and return its id
    private function failedJob(): int
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES (:uuid, 'database', 'default', '{}', 'Test failure', NOW())",
            [':uuid' => bin2hex(random_bytes(16))]
        );

        return (int) $db->getConnection()->lastInsertId();
    }

    // Number of rows left in failed_jobs
    private function failedJobCount(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) AS c FROM failed_jobs')->fetch()['c'];
    }

    // Sign in as the admin on System Overview so the CSRF token is current
    private function admin(): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->get('/system-overview');

        return $client;
    }

    // Deleting one failed job redirects to a page that exists and removes the row
    public function test_deleting_a_failed_job_returns_to_system_overview(): void
    {
        $id = $this->failedJob();
        $client = $this->admin();
        $response = $client->post("/system-health/failed-jobs/{$id}/delete", []);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/system-overview', $response['headers']['location'] ?? '');
        $this->assertSame(200, $client->get('/system-overview')['status']);
        $this->assertSame(0, $this->failedJobCount());
    }

    // Deleting all failed jobs redirects to the same page and empties the table
    public function test_deleting_all_failed_jobs_returns_to_system_overview(): void
    {
        $this->failedJob();
        $this->failedJob();
        $client = $this->admin();
        $response = $client->post('/system-health/failed-jobs/delete-all', []);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/system-overview', $response['headers']['location'] ?? '');
        $this->assertSame(0, $this->failedJobCount());
    }
}
