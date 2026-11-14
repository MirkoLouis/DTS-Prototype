<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Documents\DocumentQueryService;
use App\Modules\Integrity\HashChainService;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The officer intake list and the completed-tasks list can be filtered by the day the user handled the document.
 */
class IntakeDateFilterTest extends TestCase
{
    // Tracking codes on the first page of the officer's intake list for a given day
    private function intakeCodesFor(string $date): array
    {
        $officerId = $this->fx['users']['records.unit'];
        [$documents] = (new DocumentQueryService())->getPaginatedOfficerIntake($officerId, ['date' => $date]);

        return array_column($documents, 'tracking_code');
    }

    // A document finalized today appears under today's date (app timezone) and not under another day
    public function test_intake_date_filter_returns_documents_handled_that_day(): void
    {
        // Finalize one document as the Records Officer
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone(HashChainService::APP_TIMEZONE)))->format('Y-m-d');

        // Listed for today, absent for a day long past
        $this->assertContains($document['tracking_code'], $this->intakeCodesFor($today));
        $this->assertSame([], $this->intakeCodesFor('2000-01-01'));
    }

    // A step completed today appears in that user's completed tasks under today's date and not under another day
    public function test_completed_tasks_date_filter_returns_documents_handled_that_day(): void
    {
        // The Cash Unit completes its step on one document
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);
        $cashId = $this->fx['users']['cash.unit'];
        $today = (new \DateTimeImmutable('now', new \DateTimeZone(HashChainService::APP_TIMEZONE)))->format('Y-m-d');
        $service = new DocumentQueryService();

        // Listed for today, absent for a day long past
        [$todayDocuments] = $service->getPaginatedOfficerCompletedTasks($cashId, ['date' => $today]);
        [$oldDocuments] = $service->getPaginatedOfficerCompletedTasks($cashId, ['date' => '2000-01-01']);
        $this->assertContains($document['tracking_code'], array_column($todayDocuments, 'tracking_code'));
        $this->assertSame([], $oldDocuments);
    }

    // The filter covers the whole day: 23:59:59 belongs to that day and not to the next
    public function test_intake_date_filter_includes_the_last_second_of_the_day(): void
    {
        // Move the officer's finalize entry to the last second of a fixed day
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);
        Database::getInstance()->query(
            "UPDATE document_logs SET created_at = '2026-03-05 23:59:59' WHERE document_id = :id AND user_id = :officer",
            [':id' => $document['id'], ':officer' => $this->fx['users']['records.unit']]
        );

        $this->assertContains($document['tracking_code'], $this->intakeCodesFor('2026-03-05'));
        $this->assertNotContains($document['tracking_code'], $this->intakeCodesFor('2026-03-06'));
    }

    // A date value that is not a date is ignored instead of failing the query
    public function test_malformed_date_filter_is_ignored(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);

        $this->assertContains($document['tracking_code'], $this->intakeCodesFor('garbage'));
    }
}
