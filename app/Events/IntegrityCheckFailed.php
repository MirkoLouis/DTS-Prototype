<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IntegrityCheckFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The document that failed the integrity check.
     *
     * @var \App\Models\Document
     */
    public $document;

    /**
     * The reason or type of integrity failure.
     *
     * @var string
     */
    public $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(Document $document, string $reason)
    {
        $this->document = $document;
        $this->reason = $reason;
    }
}
