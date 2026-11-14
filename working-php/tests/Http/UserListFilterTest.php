<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * The role filter on the users page narrows by a real role and ignores anything else.
 */
class UserListFilterTest extends TestCase
{
    // Fetch the users page as the admin with the given query string
    private function usersPage(string $query): array
    {
        $client = HttpClient::start();
        $client->loginAs('admin');

        return $client->get('/users' . $query);
    }

    // "All Roles" lists accounts of every role
    public function test_all_roles_filter_lists_every_role(): void
    {
        $body = $this->usersPage('?role=all')['body'];
        $this->assertStringContainsString('cash.unit', $body);
        $this->assertStringContainsString('records.unit', $body);
    }

    // A real role narrows the list to that role
    public function test_role_filter_narrows_to_one_role(): void
    {
        $body = $this->usersPage('?role=officer')['body'];
        $this->assertStringContainsString('records.unit', $body);
        $this->assertStringNotContainsString('cash.unit', $body);
    }

    // A value that is not a role is ignored instead of emptying the list
    public function test_unknown_role_value_is_ignored(): void
    {
        $response = $this->usersPage('?role=nonsense');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('cash.unit', $response['body']);
    }

    // An array value (?role[]=a) is ignored too and never errors
    public function test_array_role_value_is_ignored(): void
    {
        $response = $this->usersPage('?role[]=staff');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('cash.unit', $response['body']);
    }
}
