<?php

namespace App\Modules\Integrity\Events;

use App\Modules\Documents\DocumentModel;

class IntegrityCheckFailedEvent
{
    public $document;
    public $reason;

    public function __construct(DocumentModel $document, string $reason)
    {
        $this->document = $document;
        $this->reason = $reason;
    }
}
