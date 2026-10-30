<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Documents\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * F1: finalizeIntake rejects routes naming departments that do not exist, before any write.
 */
class RouteValidationTest extends TestCase
{
    // Run finalizeIntake as the records officer
    private function finalize(int $id, array $route): void
    {
        (new DocumentWorkflowService())->finalizeIntake($id, $route, Fixtures::user('records.unit'), TestDatabase::PIN);
    }

    // Unknown names are rejected, named, and nothing is written
    public function test_rejects_unknown_department_and_names_it(): void
    {
        $doc = Fixtures::submit();
        $db = Database::getInstance();
        $before = $db->query('SELECT suggested_route FROM purposes')->fetchAll();

        try {
            $this->finalize($doc['id'], ['Cash Unit', 'Ministry of Magic']);
            $this->fail('Expected an exception.');
        } catch (\Exception $e) {
            $this->assertSame("Invalid route: 'Ministry of Magic' is not a department.", $e->getMessage());
        }

        $this->assertSame('pending', $db->query('SELECT status FROM documents WHERE id = :i', [':i' => $doc['id']])->fetch()['status']);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :i AND action LIKE "Accepted%"', [':i' => $doc['id']])->fetch()['c']);
        $this->assertSame($before, $db->query('SELECT suggested_route FROM purposes')->fetchAll());
    }

    // Case differences are not accepted
    public function test_rejects_wrong_case(): void
    {
        $doc = Fixtures::submit();
        $this->expectExceptionMessage("Invalid route: 'cash unit' is not a department.");
        $this->finalize($doc['id'], ['cash unit']);
    }

    // Non-string entries and empty lists are an invalid route
    public function test_rejects_non_string_entries(): void
    {
        foreach ([[['x' => 1]], []] as $route) {
            $doc = Fixtures::submit();
            try {
                $this->finalize($doc['id'], $route);
                $this->fail('Expected an exception.');
            } catch (\Exception $e) {
                $this->assertSame('Invalid route.', $e->getMessage());
            }
        }
    }

    // A route of real departments still finalizes
    public function test_accepts_valid_route(): void
    {
        $doc = Fixtures::submit();
        $this->finalize($doc['id'], ['Cash Unit', 'Personnel Unit']);

        $status = Database::getInstance()->query('SELECT status FROM documents WHERE id = :i', [':i' => $doc['id']])->fetch()['status'];
        $this->assertSame('in_transit', $status);
    }

    // The flashed error is HTML-escaped on the page it lands on
    public function test_error_message_is_escaped_on_manage_page(): void
    {
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $client->get('/documents/' . $doc['id'] . '/manage');
        $client->post('/documents/' . $doc['id'] . '/finalize', ['final_route' => json_encode(['<b>x</b>']), 'pin' => TestDatabase::PIN]);
        $page = $client->get('/documents/' . $doc['id'] . '/manage')['body'];

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $page);
        $this->assertStringNotContainsString("'<b>x</b>'", $page);
    }

    // I1: the F1 message for a script-tag name, stored as a notification, comes back from the poll API as plain JSON text.
    // The poll toast in layouts/app.php must render it with textContent; PHPUnit cannot run that JS, so the sink is covered by code review.
    public function test_script_tag_route_error_is_plain_text_in_unread_api(): void
    {
        $payload = '<script>alert(1)</script>';
        $doc = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $client->get('/documents/' . $doc['id'] . '/manage');
        $client->post('/documents/' . $doc['id'] . '/finalize', ['final_route' => json_encode([$payload]), 'pin' => TestDatabase::PIN]);

        // Rendering the next page converts the flashed error into a stored notification
        $client->get('/documents/' . $doc['id'] . '/manage');
        $response = $client->get('/api/notifications/unread');

        // The API answers as JSON, never as HTML
        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');

        // json_encode escapes '/', so the raw body never contains a closing script tag
        $this->assertStringNotContainsString('</script>', $response['body']);

        // The decoded message is the exact plain text: no HTML entities added server-side, the client sink must escape it
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $messages = array_column($data['notifications'], 'message');
        $this->assertContains("Invalid route: '{$payload}' is not a department.", $messages);
    }
}
