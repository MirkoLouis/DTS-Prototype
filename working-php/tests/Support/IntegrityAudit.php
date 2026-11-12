<?php

namespace Tests\Support;

use App\Common\Database\Database;
use App\Modules\Integrity\Jobs\IntegrityCheckJob;
use RuntimeException;

/**
 * Runs one full integrity audit exactly as the System Health page queues it.
 */
class IntegrityAudit
{
    /**
     * Queue and run an audit requested by the given user, and return its decoded results.
     */
    public static function run(int $requestedByUserId): array
    {
        $db = Database::getInstance();
        $id = bin2hex(random_bytes(16));
        $db->query("INSERT INTO integrity_checks (id, user_id, status, progress, created_at, updated_at) VALUES (:id, :u, 'queued', 0, NOW(), NOW())", [
            ':id' => $id,
            ':u' => $requestedByUserId,
        ]);

        (new IntegrityCheckJob($id))->handle();

        // An audit that did not complete is a harness error, not a detection result
        $check = $db->query('SELECT status, results, error_message FROM integrity_checks WHERE id = :id', [':id' => $id])->fetch();
        if ($check['status'] !== 'completed') {
            throw new RuntimeException('Audit failed: ' . (string) $check['error_message']);
        }

        return json_decode($check['results'], true);
    }
}
