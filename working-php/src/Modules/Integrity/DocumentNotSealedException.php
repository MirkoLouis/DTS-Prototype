<?php

namespace App\Modules\Integrity;

/**
 * A document has log entries but no chain seal yet (after the migration and before scripts/seal-chains.php,
 * or after restoring a backup from before the chain seal). It is not tampered: the action is refused, nothing is frozen.
 */
class DocumentNotSealedException extends \RuntimeException
{
    // Message shown to the user
    public const MESSAGE = 'This document has not been sealed yet; an administrator must run the sealing step.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
