<?php

namespace App\Listeners;

use App\Events\IntegrityCheckFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class AutoFreezeDocument
{
    /**
     * Handle the event.
     */
    public function handle(IntegrityCheckFailed $event): void
    {
        $document = $event->document;
        $reason = $event->reason;

        if ($document->status !== 'frozen') {
            Log::warning("SECURITY ALERT: Auto-freezing document {$document->tracking_code} due to integrity check failure: {$reason}");
            
            $document->status = 'frozen';
            $document->save();

            // Create a system log entry for the auto-freeze
            $document->logs()->create([
                'user_id' => null, // System-initiated
                'action' => 'System Auto-Freeze',
                'remarks' => "Document automatically frozen by the Trust Builder system due to an integrity mismatch detected during {$reason}.",
            ]);
        }
    }
}
