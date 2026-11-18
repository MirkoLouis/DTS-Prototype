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
        $dir = \App\Modules\System\BackupService::directory() . '/';
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
        $zip->open(\App\Modules\System\BackupService::directory() . '/' . $this->zipName, ZipArchive::CREATE);
        $zip->addFromString($sqlName, "ALTER TABLE users DROP COLUMN deactivated_at;\n");
        $zip->close();

        (new RestoreBackupJob($this->zipName))->handle();

        // Sign-in queries need the column, so the restore must not leave it missing
        $row = Database::getInstance()->query(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deactivated_at'"
        )->fetch();
        $this->assertSame(1, (int) $row['c']);
    }

    // A backup holding two departments with the same name restores, and the migration's notice reaches the audit record and the admin's notification
    public function test_restore_reports_duplicate_department_names(): void
    {
        @unlink(\App\Modules\System\SystemAuditService::path());
        $sqlName = 'test_restore_' . bin2hex(random_bytes(4)) . '.sql';
        $this->zipName = $sqlName . '.zip';
        $zip = new ZipArchive();
        $zip->open(\App\Modules\System\BackupService::directory() . '/' . $this->zipName, ZipArchive::CREATE);
        $zip->addFromString($sqlName, "ALTER TABLE departments DROP INDEX uq_departments_name;\nINSERT INTO departments (name) VALUES ('Twin Unit'), ('Twin Unit');\n");
        $zip->close();
        $adminId = $this->fx['users']['admin'];

        (new RestoreBackupJob($this->zipName, $adminId))->handle();

        // The audit record carries the notice with the duplicated name
        $lines = file(\App\Modules\System\SystemAuditService::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entry = json_decode((string) end($lines), true);
        $this->assertSame('backup_restored', $entry['event']);
        $this->assertCount(1, $entry['data']['migration_notices']);
        $this->assertStringContainsString('duplicated_department_name=Twin Unit', $entry['data']['migration_notices'][0]);

        // So does the admin's "Backup restored" notification, as a warning
        $notification = Database::getInstance()->query("SELECT message, type FROM notifications WHERE user_id = :u AND title = 'Backup restored'", [':u' => $adminId])->fetch();
        $this->assertStringContainsString('Migration notice: NOT APPLIED: departments.name has duplicates', $notification['message']);
        $this->assertStringContainsString('Twin Unit', $notification['message']);
        $this->assertSame('warning', $notification['type']);
    }
}
