<?php

namespace Tests\Unit;

use App\Modules\Documents\DocumentDenialService;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal reason DocumentDenialService gives, by policy and by document status.
 */
class DocumentDenialServiceTest extends TestCase
{
    // A document routed Cash Unit → Personnel Unit, at the given status and step
    private function document(string $status, int $step = 1): array
    {
        return [
            'status' => $status,
            'current_step' => $step,
            'finalized_route' => json_encode([['name' => 'Cash Unit'], ['name' => 'Personnel Unit']]),
        ];
    }

    // Administrator-only actions answer with the role, even for a frozen document
    public function test_freeze_and_unfreeze_refusals_name_the_administrator_role(): void
    {
        foreach (['freeze', 'unfreeze'] as $policy) {
            $this->assertSame(DocumentDenialService::ADMIN_ONLY, DocumentDenialService::messageFor($policy, $this->document('frozen')));
        }
    }

    // A frozen document is reported as frozen for receive, complete and officer actions
    public function test_frozen_document_is_reported_as_frozen(): void
    {
        foreach (['process', 'manage'] as $policy) {
            $this->assertSame(DocumentDenialService::FROZEN, DocumentDenialService::messageFor($policy, $this->document('frozen')));
        }
    }

    // Officer actions refused for any other reason name the officer and administrator roles
    public function test_manage_refusal_names_the_roles(): void
    {
        $this->assertSame(DocumentDenialService::MANAGE_ONLY, DocumentDenialService::messageFor('manage', $this->document('pending', 0)));
    }

    // Receive and complete refusals say where the document is, for every status
    public function test_step_reasons_follow_the_status(): void
    {
        $cases = [
            [$this->document('in_transit', 2), 'This document is not for your department. It is waiting to be received by Personnel Unit.'],
            [$this->document('in_transit', 3), 'This document is not for your department. It is waiting to be received by the Records Unit.'],
            [$this->document('processing', 1), 'This document is being processed by the Cash Unit.'],
            [$this->document('pending', 0), 'This document is still pending intake by the Records Office and cannot be received yet.'],
            [$this->document('ready_for_release', 3), 'This document is already in the Awaiting Release list.'],
            [$this->document('completed', 3), 'This document has already been released or declined.'],
            [$this->document('declined', 0), 'This document has already been released or declined.'],
            [$this->document('unknown', 1), DocumentDenialService::GENERIC],
        ];
        foreach ($cases as [$document, $expected]) {
            $this->assertSame($expected, DocumentDenialService::messageFor('process', $document), $document['status']);
        }
    }
}
