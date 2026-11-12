<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Modules\Users\UserService;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\IntegrityAudit;
use Tests\TestCase;

/**
 * The users page deactivates and reactivates accounts; it never deletes them.
 */
class UserDeactivationTest extends TestCase
{
    // Read a user's deactivated_at value, or false when the row is gone
    private function deactivatedAt(int $userId): string|null|false
    {
        $row = Database::getInstance()->query('SELECT deactivated_at FROM users WHERE id = :id', [':id' => $userId])->fetch();

        return $row ? $row['deactivated_at'] : false;
    }

    // Sign in as the admin and open the users page so the CSRF token is current
    private function admin(): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->get('/users');

        return $client;
    }

    // Delete deactivates; the user's signed entries keep their author and the audit stays at 100 %
    public function test_delete_route_deactivates_and_chain_stays_verifiable(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $cash = $this->fx['users']['cash.unit'];
        $signedBefore = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs WHERE user_id = :u', [':u' => $cash])->fetch()['c'];

        $response = $this->admin()->post("/users/{$cash}/delete", []);

        $this->assertSame(302, $response['status']);
        $this->assertStringEndsWith('/users', $response['headers']['location']);
        $this->assertIsString($this->deactivatedAt($cash));
        $signedAfter = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs WHERE user_id = :u', [':u' => $cash])->fetch()['c'];
        $this->assertGreaterThan(0, $signedBefore);
        $this->assertSame($signedBefore, $signedAfter);

        $results = IntegrityAudit::run($this->fx['users']['admin']);
        $this->assertEquals(100.0, $results['verified_percentage']);
        $this->assertSame(0, $results['invalid_signatures']);
    }

    // The admin cannot deactivate their own account
    public function test_admin_cannot_deactivate_self(): void
    {
        $adminId = $this->fx['users']['admin'];
        $client = $this->admin();

        $client->post("/users/{$adminId}/delete", []);

        $this->assertNull($this->deactivatedAt($adminId));
        $this->assertStringContainsString('You cannot deactivate your own account.', $client->get('/users')['body']);
    }

    // Staff cannot reach the deactivate route
    public function test_staff_cannot_deactivate(): void
    {
        $personnel = $this->fx['users']['personnel.unit'];
        $client = HttpClient::start();
        $client->loginAs('cash.unit');

        $client->post("/users/{$personnel}/delete", []);

        $this->assertNull($this->deactivatedAt($personnel));
    }

    // Deactivated rows show a badge and a Reactivate action instead of Deactivate
    public function test_users_list_shows_deactivated_with_reactivate(): void
    {
        $cash = $this->fx['users']['cash.unit'];
        (new UserService())->deactivate($cash, $this->fx['users']['admin']);

        $body = $this->admin()->get('/users')['body'];

        $this->assertStringContainsString('Deactivated', $body);
        $this->assertStringContainsString("action=\"/users/{$cash}/reactivate\"", $body);
        $this->assertStringNotContainsString("action=\"/users/{$cash}/delete\"", $body);
    }

    // Reactivate clears the deactivation
    public function test_reactivate_restores_account(): void
    {
        $cash = $this->fx['users']['cash.unit'];
        (new UserService())->deactivate($cash, $this->fx['users']['admin']);

        $response = $this->admin()->post("/users/{$cash}/reactivate", []);

        $this->assertSame(302, $response['status']);
        $this->assertNull($this->deactivatedAt($cash));
    }
}
