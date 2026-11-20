<?php

namespace App\Modules\Documents;

/**
 * A document policy refused a workflow action. The message is written for the user and is shown on the page;
 * nothing was changed, except an automatic freeze when the pre-action integrity check found tampering.
 */
class ActionDeniedException extends \Exception
{
}
