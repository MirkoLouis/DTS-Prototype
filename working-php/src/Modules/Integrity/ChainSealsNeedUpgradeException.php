<?php

namespace App\Modules\Integrity;

/**
 * A document's chain seal still verifies on the old version (v1) because the upgrade has not been run yet. It is not
 * tampered: the action is refused, nothing is frozen, through the same path as DocumentNotSealedException.
 */
class ChainSealsNeedUpgradeException extends DocumentNotSealedException
{
    // Message shown to the user
    public const UPGRADE_MESSAGE = 'Seals need upgrading: an administrator must run php scripts/seal-chains.php --upgrade-chain-seals';

    public function __construct()
    {
        // Skip the parent constructor, which sets the "not sealed yet" message
        \RuntimeException::__construct(self::UPGRADE_MESSAGE);
    }
}
