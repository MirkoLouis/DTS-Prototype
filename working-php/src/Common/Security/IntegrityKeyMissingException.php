<?php

namespace App\Common\Security;

/**
 * Raised when something needs APP_INTEGRITY_KEY and none is configured: the caller must refuse (fail closed).
 */
class IntegrityKeyMissingException extends \RuntimeException
{
}
