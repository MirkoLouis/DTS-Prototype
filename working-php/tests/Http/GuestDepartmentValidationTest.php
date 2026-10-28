<?php

namespace Tests\Http;

use App\Core\Database;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * I1 (b): POST /submit-document accepts only a department that exists in departments.name.
 */
class GuestDepartmentValidationTest extends TestCase
{
    // Build a complete guest submission with the given department
    private function fields(string $department): array
    {
        return [
            'guest_name' => 'Department Check Guest',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '09171234567',
            'district' => 'N/A',
            'department' => $department,
            'title' => 'I1 department check',
            'purpose_id' => (string) reset($this->fx['purposes']),
        ];
    }

    // Count all stored documents
    private function documentCount(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents')->fetch()['c'];
    }

    // A forged department is rejected with a field error, the old input is kept, and no document is created
    public function test_forged_department_is_rejected_and_creates_no_document(): void
    {
        $before = $this->documentCount();
        $client = HttpClient::start();
        $client->get('/');
        $response = $client->post('/submit-document', $this->fields('<img src=x onerror=alert(1)>'));

        $this->assertSame(302, $response['status']);
        $this->assertSame('/', $response['headers']['location'] ?? '');
        $this->assertSame($before, $this->documentCount());

        // The bounce page shows the message and keeps the other old input
        $page = $client->get('/')['body'];
        $this->assertStringContainsString('Please choose a valid department.', $page);
        $this->assertStringContainsString('value="Department Check Guest"', $page);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $page);
    }

    // Case differences do not match a department
    public function test_wrong_case_department_is_rejected(): void
    {
        $before = $this->documentCount();
        $client = HttpClient::start();
        $client->get('/');
        $response = $client->post('/submit-document', $this->fields('records unit'));

        $this->assertSame('/', $response['headers']['location'] ?? '');
        $this->assertSame($before, $this->documentCount());
    }

    // A real department still submits
    public function test_real_department_is_accepted(): void
    {
        $before = $this->documentCount();
        $client = HttpClient::start();
        $client->get('/');
        $response = $client->post('/submit-document', $this->fields('Records Unit'));

        $this->assertStringStartsWith('/success', $response['headers']['location'] ?? '');
        $this->assertSame($before + 1, $this->documentCount());
    }
}
