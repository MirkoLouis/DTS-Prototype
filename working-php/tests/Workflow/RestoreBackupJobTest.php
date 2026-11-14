<?php

namespace Tests\Workflow;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Modules\System\BackupService;
use App\Modules\System\Jobs\CreateBackupJob;
use App\Modules\System\Jobs\RestoreBackupJob;
use App\Modules\System\SystemAuditService;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * A restore first saves what it is about to overwrite, is recorded in the system audit log, and tells the admin what changed.
 */
class RestoreBackupJobTest extends TestCase
{
    // Start every test with an empty backup folder and no audit log
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(BackupService::directory() . '/*') ?: [] as $file) {
            unlink($file);
        }
        @unlink(SystemAuditService::path());
    }

    // Back up a database holding one document, then add a second one; returns [backup name, first, second]
    private function backupThenAddDocument(): array
    {
        $first = Fixtures::submit();
        $backup = (new CreateBackupJob())->handle();
        $second = Fixtures::submit();

        return [$backup, $first, $second];
    }

    // Whether a document with this tracking code is in the database
    private function documentExists(string $trackingCode): bool
    {
        return (bool) Database::getInstance()->query('SELECT 1 FROM documents WHERE tracking_code = :c', [':c' => $trackingCode])->fetch();
    }

    // Names of the safety backups in the backup folder
    private function safetyBackups(): array
    {
        return array_map('basename', glob(BackupService::directory() . '/*_pre-restore_*.zip') ?: []);
    }

    // The newest entry of the audit log
    private function lastAuditEntry(): array
    {
        $lines = file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return json_decode((string) end($lines), true);
    }

    // The SQL text inside a backup zip
    private function sqlOf(string $zipName): string
    {
        $zip = new \ZipArchive();
        $zip->open(BackupService::directory() . '/' . $zipName);
        $zip->setPassword((string) Config::get('app', 'backup_password', ''));
        $sql = (string) $zip->getFromIndex(0);
        $zip->close();

        return $sql;
    }

    // Before anything is overwritten, the current database is saved as a pre-restore backup
    public function test_restore_first_saves_the_current_database(): void
    {
        [$backup, $first, $second] = $this->backupThenAddDocument();
        (new RestoreBackupJob($backup, $this->fx['users']['admin']))->handle();

        // The restore went back to one document
        $this->assertTrue($this->documentExists($first['tracking_code']));
        $this->assertFalse($this->documentExists($second['tracking_code']));

        // The document that was lost is in the safety backup
        $safety = $this->safetyBackups();
        $this->assertCount(1, $safety);
        $this->assertStringContainsString($second['tracking_code'], $this->sqlOf($safety[0]));
    }

    // The restore is recorded with what it changed, and the admin who asked for it is told
    public function test_restore_is_recorded_with_counts_and_notifies_the_admin(): void
    {
        [$backup] = $this->backupThenAddDocument();
        $adminId = $this->fx['users']['admin'];
        (new RestoreBackupJob($backup, $adminId))->handle();

        $entry = $this->lastAuditEntry();
        $this->assertSame('backup_restored', $entry['event']);
        $this->assertSame($backup, $entry['data']['file']);
        $this->assertSame($adminId, $entry['data']['user_id']);
        $this->assertSame($this->safetyBackups()[0], $entry['data']['safety_backup']);
        $this->assertSame(2, $entry['data']['documents_before']);
        $this->assertSame(1, $entry['data']['documents_after']);
        $this->assertSame([], SystemAuditService::verify()['bad_lines']);

        // One newer document is gone, so the notification is a warning that says so
        $notification = Database::getInstance()->query(
            'SELECT title, message, type FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 1',
            [':u' => $adminId]
        )->fetch();
        $this->assertSame('Backup restored', $notification['title']);
        $this->assertSame('warning', $notification['type']);
        $this->assertStringContainsString('2 before, 1 after', $notification['message']);
        $this->assertStringContainsString('1 newer document(s) are no longer in the database', $notification['message']);
        $this->assertStringContainsString($this->safetyBackups()[0], $notification['message']);
    }

    // A backup that does not exist is recorded as a failed restore, and nothing else happens
    public function test_missing_backup_is_recorded_and_restores_nothing(): void
    {
        $document = Fixtures::submit();
        (new RestoreBackupJob('no-such-backup.sql.zip', $this->fx['users']['admin']))->handle();

        $entry = $this->lastAuditEntry();
        $this->assertSame('backup_restore_failed', $entry['event']);
        $this->assertSame('file not found', $entry['data']['reason']);
        $this->assertSame([], $this->safetyBackups());
        $this->assertTrue($this->documentExists($document['tracking_code']));
    }

    // When the current database cannot be saved first, the restore does not run
    public function test_failed_safety_backup_stops_the_restore(): void
    {
        [$backup, , $second] = $this->backupThenAddDocument();

        // A restore job whose safety backup fails
        $job = new class($backup, $this->fx['users']['admin']) extends RestoreBackupJob {
            protected function safetyBackup(): string
            {
                throw new \RuntimeException('Disk is full.');
            }
        };

        try {
            $job->handle();
            $this->fail('The restore ran without a safety backup.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Disk is full.', $e->getMessage());
        }

        // Nothing was restored, and the failure is on record
        $this->assertTrue($this->documentExists($second['tracking_code']));
        $entry = $this->lastAuditEntry();
        $this->assertSame('backup_restore_failed', $entry['event']);
        $this->assertStringContainsString('Disk is full.', $entry['data']['reason']);
    }

    // A backup made with another password is not restored; the data stays and the failure is recorded
    public function test_wrong_backup_password_is_recorded_and_leaves_the_data(): void
    {
        $document = Fixtures::submit();

        // An archive encrypted under a different password than the configured one
        $directory = BackupService::directory();
        file_put_contents("{$directory}/other.sql", "DELETE FROM documents;\n");
        BackupService::archive("{$directory}/other.sql", "{$directory}/other.sql.zip", 'a different password');
        unlink("{$directory}/other.sql");

        try {
            (new RestoreBackupJob('other.sql.zip', $this->fx['users']['admin']))->handle();
            $this->fail('A backup with the wrong password was restored.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('BACKUP_PASSWORD', $e->getMessage());
        }

        $this->assertTrue($this->documentExists($document['tracking_code']));
        $entry = $this->lastAuditEntry();
        $this->assertSame('backup_restore_failed', $entry['event']);
        $this->assertStringContainsString('BACKUP_PASSWORD', $entry['data']['reason']);
        $this->assertFileDoesNotExist("{$directory}/other.sql");
    }
}
