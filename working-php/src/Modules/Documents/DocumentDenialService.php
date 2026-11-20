<?php

namespace App\Modules\Documents;

/**
 * The user-facing reason a document policy refused an action, built in one place for every workflow action.
 *
 * The reason comes from the policy that refused and from the document as it is after the refusal: a document the
 * pre-action integrity check has just frozen is reported as frozen, whatever the user tried to do.
 */
class DocumentDenialService
{
    // Shown when the document is frozen (just now by the pre-action integrity check, or earlier)
    public const FROZEN = 'This document is frozen because its record does not match its hash chain. An administrator must review it.';

    // Shown for the officer and administrator actions (finalize, decline, release)
    public const MANAGE_ONLY = 'Only Records Officers and administrators can do this.';

    // Shown for the administrator-only actions (freeze, unfreeze, auto-resolve)
    public const ADMIN_ONLY = 'Only administrators can do this.';

    // Fallback when no more specific reason applies
    public const GENERIC = 'You cannot perform this action on this document.';

    /**
     * The reason for a refusal by the given policy method, for the document as it is now.
     *
     * @param string $policyMethod the DocumentPolicy method that refused (process, manage, freeze, unfreeze)
     * @param array $document the document row, with its current status
     */
    public static function messageFor(string $policyMethod, array $document): string
    {
        // Administrator-only actions are refused by role, whatever the document's state
        if ($policyMethod === 'freeze' || $policyMethod === 'unfreeze') {
            return self::ADMIN_ONLY;
        }

        // A frozen document cannot be acted on until an administrator reviews it
        if (($document['status'] ?? '') === 'frozen') {
            return self::FROZEN;
        }

        // Officer actions are refused by role
        if ($policyMethod === 'manage') {
            return self::MANAGE_ONLY;
        }

        // Receiving and completing: the department-step rule; say where the document is
        return self::stepReason($document);
    }

    /**
     * Why a department other than the current step cannot receive or complete the document.
     */
    private static function stepReason(array $document): string
    {
        $route = !empty($document['finalized_route']) ? (json_decode($document['finalized_route'], true) ?: []) : [];
        $stepIndex = ((int) ($document['current_step'] ?? 0)) - 1;
        $holder = $route[$stepIndex]['name'] ?? null;

        // The reason follows the document's status
        return match ($document['status'] ?? '') {
            'in_transit' => $stepIndex >= count($route)
                ? 'This document is not for your department. It is waiting to be received by the Records Unit.'
                : 'This document is not for your department. It is waiting to be received by ' . ($holder ?? 'another department') . '.',
            'processing' => 'This document is being processed by the ' . ($holder ?? 'another department') . '.',
            'pending' => 'This document is still pending intake by the Records Office and cannot be received yet.',
            'ready_for_release' => 'This document is already in the Awaiting Release list.',
            'completed', 'declined' => 'This document has already been released or declined.',
            default => self::GENERIC,
        };
    }
}
