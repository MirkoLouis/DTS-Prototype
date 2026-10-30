<?php

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class IctUnitTest extends TestCase
{
    // The production seeder creates the ICT Unit, and the test fixture mirrors its department list exactly
    public function test_production_seeder_includes_ict_unit_and_matches_fixture(): void
    {
        // Read the department array literal from the production seeder
        $source = file_get_contents(BASE_PATH . '/scripts/seeders/setup-production-db.js');
        $this->assertSame(1, preg_match('/const departments = \[(.*?)\];/s', $source, $m), 'departments array not found in setup-production-db.js');
        preg_match_all("/'([^']+)'/", $m[1], $names);

        // ICT Unit is seeded, and the fixture's list is the same set
        $this->assertContains('ICT Unit', $names[1]);
        $seeded = $names[1];
        $fixture = TestDatabase::DEPARTMENTS;
        sort($seeded);
        sort($fixture);
        $this->assertSame($seeded, $fixture);
    }

    // Records officers can add the ICT Unit as a step when finalizing a route
    public function test_ict_unit_is_selectable_in_intake_route_builder(): void
    {
        // Open a pending document's manage page as the Records Officer
        $s = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $page = $client->get("/documents/{$s['id']}/manage")['body'];

        // The route builder lists the ICT Unit
        $this->assertStringContainsString('ICT Unit', $page);
    }
}
