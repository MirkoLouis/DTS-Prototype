<?php

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * F2: document detail and hash-chain pages honour DocumentPolicy::view.
 */
class DocumentVisibilityTest extends TestCase
{
    private const DENIED = 'You do not have access to this document.';

    // Build a document routed only through the Personnel Unit
    private function personnelDoc(): array
    {
        $doc = Fixtures::submit(['guest_name' => 'Outside Route Person']);
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Personnel Unit']);

        return $doc;
    }

    // Staff whose department is on the route can open the page
    public function test_staff_on_route_can_view(): void
    {
        $doc = $this->personnelDoc();
        $client = HttpClient::start();
        $client->loginAs('personnel.unit');
        $response = $client->get('/documents/' . $doc['tracking_code']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Outside Route Person', $response['body']);
    }

    // Staff off the route are redirected with an error and nothing leaks
    public function test_staff_off_route_is_redirected_with_error(): void
    {
        $doc = $this->personnelDoc();
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $response = $client->get('/documents/' . $doc['tracking_code']);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/dashboard', $response['headers']['location'] ?? $response['headers']['Location'] ?? null);
        $this->assertStringNotContainsString('Outside Route Person', $response['body']);

        // The flash message surfaces on the next app page
        $client->get('/dashboard');
        $page = $client->get('/tasks')['body'];
        $this->assertStringContainsString(self::DENIED, $page);
    }

    // The hash-chain page is guarded the same way
    public function test_staff_off_route_cannot_view_hash_chain(): void
    {
        $doc = $this->personnelDoc();
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $response = $client->get('/documents/' . $doc['tracking_code'] . '/hash-chain');

        $this->assertSame(302, $response['status']);
        $this->assertStringNotContainsString('Outside Route Person', $response['body']);
    }

    // Officers and admins keep global visibility
    public function test_officer_and_admin_can_view_any(): void
    {
        $doc = $this->personnelDoc();
        foreach (['records.unit', 'admin'] as $username) {
            $client = HttpClient::start();
            $client->loginAs($username);
            $this->assertSame(200, $client->get('/documents/' . $doc['tracking_code'])['status'], $username);
        }
    }
}
