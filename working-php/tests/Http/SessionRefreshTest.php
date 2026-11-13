<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * A signed-in user's role and department follow the database on the next request, not only at login.
 */
class SessionRefreshTest extends TestCase
{
    // Id of a department by name
    private function departmentId(string $name): int
    {
        return (int) Database::getInstance()->query('SELECT id FROM departments WHERE name = :name', [':name' => $name])->fetch()['id'];
    }

    // Moving a signed-in user to another department changes their task list without a new login
    public function test_department_change_applies_on_next_request(): void
    {
        // Personnel staff sees the Personnel Unit task page
        $client = HttpClient::start();
        $client->loginAs('personnel.unit');
        $this->assertStringContainsString('Personnel Unit Tasks', $client->get('/tasks')['body']);

        // The admin moves the account to the Cash Unit while it stays signed in
        Database::getInstance()->query('UPDATE users SET department_id = :dept WHERE id = :id', [
            ':dept' => $this->departmentId('Cash Unit'),
            ':id' => $this->fx['users']['personnel.unit'],
        ]);

        // The very next page is the Cash Unit's
        $body = $client->get('/tasks')['body'];
        $this->assertStringContainsString('Cash Unit Tasks', $body);
        $this->assertStringNotContainsString('Personnel Unit Tasks', $body);
    }

    // Demoting a signed-in admin removes admin access on the next request
    public function test_role_change_applies_on_next_request(): void
    {
        // The admin can open the users page
        $client = HttpClient::start();
        $client->loginAs('admin');
        $this->assertSame(200, $client->get('/users')['status']);

        // The account is demoted to Cash Unit staff while it stays signed in
        Database::getInstance()->query("UPDATE users SET role = 'staff', department_id = :dept WHERE id = :id", [
            ':dept' => $this->departmentId('Cash Unit'),
            ':id' => $this->fx['users']['admin'],
        ]);

        // Admin pages are refused, staff pages work
        $this->assertNotSame(200, $client->get('/users')['status']);
        $this->assertSame(200, $client->get('/tasks')['status']);
    }
}
