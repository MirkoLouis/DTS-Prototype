<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * Accounts get a department when the admin creates or edits them: staff and Records Officers need
 * one, a Records Officer belongs to the Records Unit, and an admin has none.
 */
class UserDepartmentTest extends TestCase
{
    // Sign in as the admin on the create form so the CSRF token is current
    private function admin(): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->get('/users/create');

        return $client;
    }

    // Id of a department by name
    private function departmentId(string $name): int
    {
        return (int) Database::getInstance()->query('SELECT id FROM departments WHERE name = :name', [':name' => $name])->fetch()['id'];
    }

    // The users row for a username, or false when it does not exist
    private function user(string $username): array|false
    {
        return Database::getInstance()->query('SELECT id, role, department_id FROM users WHERE email = :email', [':email' => $username])->fetch();
    }

    // Form fields for a new account
    private function newUser(string $username, string $role, string|int $departmentId): array
    {
        return [
            'name' => 'New Account',
            'email' => $username,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => $role,
            'department_id' => $departmentId,
        ];
    }

    // Text of the error box on a form page
    private function errorOn(HttpClient $client, string $path): string
    {
        preg_match('/role="alert".*?<\/div>/s', $client->get($path)['body'], $match);

        return $match[0] ?? '';
    }

    // A staff account is saved with the department the admin chose
    public function test_staff_is_created_with_the_chosen_department(): void
    {
        $cash = $this->departmentId('Cash Unit');
        $response = $this->admin()->post('/users', $this->newUser('new.staff', 'staff', $cash));

        $this->assertSame('/users', $response['headers']['location'] ?? '');
        $this->assertSame($cash, (int) $this->user('new.staff')['department_id']);
    }

    // A staff account without a department is refused and not created
    public function test_staff_without_department_is_rejected(): void
    {
        $client = $this->admin();
        $response = $client->post('/users', $this->newUser('no.dept', 'staff', ''));

        $this->assertSame('/users/create', $response['headers']['location'] ?? '');
        $this->assertStringContainsString('Please choose a department for this account.', $this->errorOn($client, '/users/create'));
        $this->assertFalse($this->user('no.dept'));
    }

    // A department id that does not exist is refused the same way
    public function test_unknown_department_is_rejected(): void
    {
        $client = $this->admin();
        $client->post('/users', $this->newUser('bad.dept', 'staff', 999999));

        $this->assertStringContainsString('Please choose a department for this account.', $this->errorOn($client, '/users/create'));
        $this->assertFalse($this->user('bad.dept'));
    }

    // A Records Officer must belong to the Records Unit
    public function test_officer_outside_records_unit_is_rejected(): void
    {
        $client = $this->admin();
        $client->post('/users', $this->newUser('wrong.officer', 'officer', $this->departmentId('Cash Unit')));

        $this->assertStringContainsString('Records Officers must belong to the Records Unit.', $this->errorOn($client, '/users/create'));
        $this->assertFalse($this->user('wrong.officer'));
    }

    // A Records Officer in the Records Unit is accepted
    public function test_officer_in_records_unit_is_created(): void
    {
        $records = $this->departmentId('Records Unit');
        $this->admin()->post('/users', $this->newUser('second.officer', 'officer', $records));

        $this->assertSame($records, (int) $this->user('second.officer')['department_id']);
    }

    // An admin account never gets a department, whatever the form sent
    public function test_admin_is_created_without_department(): void
    {
        $this->admin()->post('/users', $this->newUser('second.admin', 'admin', $this->departmentId('Cash Unit')));

        $user = $this->user('second.admin');
        $this->assertNotFalse($user);
        $this->assertNull($user['department_id']);
    }

    // A role outside staff, officer and admin is refused
    public function test_invalid_role_is_rejected(): void
    {
        $client = $this->admin();
        $client->post('/users', $this->newUser('super.user', 'superuser', $this->departmentId('Cash Unit')));

        $this->assertStringContainsString('Please choose a valid role.', $this->errorOn($client, '/users/create'));
        $this->assertFalse($this->user('super.user'));
    }

    // Editing an account moves it to another department
    public function test_edit_changes_department(): void
    {
        $id = $this->fx['users']['personnel.unit'];
        $cash = $this->departmentId('Cash Unit');
        $this->admin()->post("/users/{$id}/update", ['name' => 'Personnel Unit Staff', 'email' => 'personnel.unit', 'role' => 'staff', 'department_id' => $cash]);

        $this->assertSame($cash, (int) $this->user('personnel.unit')['department_id']);
    }

    // The edit form shows the current department selected, and saving it unchanged keeps it
    public function test_edit_keeps_department_when_unchanged(): void
    {
        $id = $this->fx['users']['personnel.unit'];
        $personnel = $this->departmentId('Personnel Unit');
        $client = $this->admin();
        $form = $client->get("/users/{$id}/edit")['body'];

        // The current department is the selected option
        $this->assertMatchesRegularExpression('/<option value="' . $personnel . '"\s+selected>/', $form);
        $client->post("/users/{$id}/update", ['name' => 'Personnel Unit Staff', 'email' => 'personnel.unit', 'role' => 'staff', 'department_id' => $personnel]);
        $this->assertSame($personnel, (int) $this->user('personnel.unit')['department_id']);
    }

    // Editing cannot strip the department from a staff account
    public function test_edit_without_department_is_rejected(): void
    {
        $id = $this->fx['users']['personnel.unit'];
        $personnel = $this->departmentId('Personnel Unit');
        $client = $this->admin();
        $client->post("/users/{$id}/update", ['name' => 'Personnel Unit Staff', 'email' => 'personnel.unit', 'role' => 'staff', 'department_id' => '']);

        $this->assertStringContainsString('Please choose a department for this account.', $this->errorOn($client, "/users/{$id}/edit"));
        $this->assertSame($personnel, (int) $this->user('personnel.unit')['department_id']);
    }

    // Both forms offer the department list
    public function test_forms_list_departments(): void
    {
        $client = $this->admin();
        $create = $client->get('/users/create')['body'];

        $this->assertStringContainsString('name="department_id"', $create);
        $this->assertStringContainsString('ICT Unit', $create);
        $this->assertStringContainsString('name="department_id"', $client->get('/users/' . $this->fx['users']['cash.unit'] . '/edit')['body']);
    }

    // The Records Unit rule also applies when an account is edited into the officer role
    public function test_edit_to_officer_outside_records_unit_is_rejected(): void
    {
        $id = $this->fx['users']['cash.unit'];
        $cash = $this->departmentId('Cash Unit');
        $client = $this->admin();
        $client->post("/users/{$id}/update", ['name' => 'Cash Unit Staff', 'email' => 'cash.unit', 'role' => 'officer', 'department_id' => $cash]);

        $this->assertStringContainsString('Records Officers must belong to the Records Unit.', $this->errorOn($client, "/users/{$id}/edit"));
        $this->assertSame('staff', $this->user('cash.unit')['role']);
    }

    // An invalid role is refused on edit as well, and the account keeps its role
    public function test_edit_with_invalid_role_is_rejected(): void
    {
        $id = $this->fx['users']['cash.unit'];
        $client = $this->admin();
        $client->post("/users/{$id}/update", ['name' => 'Cash Unit Staff', 'email' => 'cash.unit', 'role' => 'superuser', 'department_id' => $this->departmentId('Cash Unit')]);

        $this->assertStringContainsString('Please choose a valid role.', $this->errorOn($client, "/users/{$id}/edit"));
        $this->assertSame('staff', $this->user('cash.unit')['role']);
    }

    // Changing the password and the department in one edit saves both
    public function test_edit_with_new_password_also_saves_department(): void
    {
        $id = $this->fx['users']['personnel.unit'];
        $cash = $this->departmentId('Cash Unit');
        $this->admin()->post("/users/{$id}/update", [
            'name' => 'Personnel Unit Staff', 'email' => 'personnel.unit', 'role' => 'staff', 'department_id' => $cash,
            'password' => 'newpassword1', 'password_confirmation' => 'newpassword1',
        ]);

        $this->assertSame($cash, (int) $this->user('personnel.unit')['department_id']);
        $hash = Database::getInstance()->query('SELECT password FROM users WHERE id = :id', [':id' => $id])->fetch()['password'];
        $this->assertTrue(password_verify('newpassword1', $hash));
    }

    // A role sent as an array is refused like any other invalid role, not answered with a server error
    public function test_array_role_is_rejected_without_server_error(): void
    {
        $client = $this->admin();
        $fields = $this->newUser('array.role', 'staff', $this->departmentId('Cash Unit'));
        $fields['role'] = ['staff'];
        $response = $client->post('/users', $fields);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/users/create', $response['headers']['location'] ?? '');
        $this->assertFalse($this->user('array.role'));
    }
}
