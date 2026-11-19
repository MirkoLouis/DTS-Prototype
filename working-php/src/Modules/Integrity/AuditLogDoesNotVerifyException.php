<?php

namespace App\Modules\Integrity;

/**
 * A document's chain seal verifies on the old version (v1), but the system audit log that records whether the upgrade
 * happened does not verify, so v1 can be neither accepted nor rejected. The action is refused and nothing is frozen,
 * through the same path as DocumentNotSealedException.
 */
class AuditLogDoesNotVerifyException extends DocumentNotSealedException
{
    // Message shown to the user
    public const LOG_MESSAGE = 'The system audit log does not verify; seals cannot be upgraded until it is restored';

    public function __construct()
    {
        // Skip the parent constructor, which sets the "not sealed yet" message
        \RuntimeException::__construct(self::LOG_MESSAGE);
    }
}
