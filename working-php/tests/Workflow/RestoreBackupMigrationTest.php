<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\System\Jobs\RestoreBackupJob;
use Tests\TestCase;
use ZipArchive;

/**
 * Restoring a backup taken before a schema change brings the schema back up to date.
 */
class RestoreBackupMigrationTest extends TestCase
{
    private string $zipName;

    // Remove the test backup archive and any SQL file left behind
    protected function tearDown(): void
    {
        $dir = BASE_PATH . '/storage/app/backups/';
        @unlink($dir . $this->zipName);
        @unlink($dir . str_replace('.zip', '', $this->zipName));
        parent::tearDown();
    }

    // A pre-2.7.0 dump recreates users without deactivated_at; the restore must re-apply the migration
    public function test_restore_of_pre_deactivation_backup_reapplies_migration(): void
    {
        // A minimal "old" dump: it leaves users in its pre-2.7.0 shape
        $sqlName = 'test_restore_' . bin2hex(random_bytes(4)) . '.sql';
        $this->zipName = $sqlName . '.zip';
        $zip = new ZipArchive();
        $zip->open(BASE_PATH . '/storage/app/backups/' . $this->zipName, ZipArchive::CREATE);
        $zip->addFromString($sqlName, "ALTER TABLE users DROP COLUMN deactivated_at;\n");
        $zip->close();

        (new RestoreBackupJob($this->zipName))->handle();

        // Sign-in queries need the column, so the restore must not leave it missing
        $row = Database::getInstance()->query(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deactivated_at'"
        )->fetch();
        $this->assertSame(1, (int) $row['c']);
    }
}
