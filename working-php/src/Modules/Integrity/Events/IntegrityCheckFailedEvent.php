<?php

namespace App\Modules\Integrity\Events;

use App\Models\Document;

class IntegrityCheckFailedEvent
{
    public $document;
    public $reason;

    public function __construct(Document $document, string $reason)
    {
        $this->document = $document;
        $this->reason = $reason;
    }
}
