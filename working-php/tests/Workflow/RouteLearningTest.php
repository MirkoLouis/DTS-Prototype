<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Documents\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class RouteLearningTest extends TestCase
{
    // Read the stored suggested route of a purpose
    private function storedRoute(int $purposeId): ?string
    {
        return Database::getInstance()->query('SELECT suggested_route FROM purposes WHERE id = :id', [':id' => $purposeId])->fetch()['suggested_route'];
    }

    // Finalizing an "Others" document must not write to the purpose
    public function test_finalizing_others_document_does_not_update_purpose(): void
    {
        $others = $this->fx['purposes']['Others'];
        $doc = Fixtures::submit(['purpose_id' => $others]);

        (new DocumentWorkflowService())->finalizeIntake($doc['id'], ['Cash Unit', 'Personnel Unit'], Fixtures::user('records.unit'), TestDatabase::PIN);

        $this->assertSame([], json_decode($this->storedRoute($others), true));
    }

    // Finalizing an official document learns the route, without the guest's unit
    public function test_finalizing_official_document_updates_purpose_route(): void
    {
        $purposeId = (int) Database::getInstance()->query('SELECT id FROM purposes WHERE is_official = 1 ORDER BY id LIMIT 1')->fetch()['id'];
        $doc = Fixtures::submit(['purpose_id' => $purposeId, 'department' => 'Records Unit']);

        (new DocumentWorkflowService())->finalizeIntake($doc['id'], ['Records Unit', 'Cash Unit', 'Personnel Unit'], Fixtures::user('records.unit'), TestDatabase::PIN);

        $this->assertSame(['Cash Unit', 'Personnel Unit'], json_decode($this->storedRoute($purposeId), true));
    }
}
