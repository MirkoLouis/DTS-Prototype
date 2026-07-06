<?php

namespace App\Listeners;

use App\Events\IntegrityCheckFailed;
use App\Core\Database;

class AutoFreezeDocument
{
    public function handle(IntegrityCheckFailed $event): void
    {
        $document = $event->document;
        $reason = $event->reason;

        if ($document->status !== 'frozen') {
            $db = Database::getInstance();
            
            $db->query("UPDATE documents SET status = 'frozen' WHERE id = :id", ['id' => $document->id]);

            // Create a system log entry for the auto-freeze
            \App\Core\IntegrityManager::createLog(
                $document->id, 
                0, // System-initiated, use 0 or leave it nullable
                'System Auto-Freeze', 
                "Document automatically frozen by the Trust Builder system due to an integrity mismatch detected during {$reason}.", 
                $document
            );
        }
    }
}
