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

    // Working folders currently in the test temporary root
    private function workingFolders(): array
    {
        return glob(Config::testPath('temp') . '/*', GLOB_ONLYDIR) ?: [];
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
        $this->assertSame([], $this->workingFolders());
        $this->assertSame([], glob(BackupService::directory() . '/*.sql') ?: []);
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

    // Restoring data that is not sealed (a backup from before the chain seal) says in the audit record and the notification that the sealing step must be run
    public function test_restoring_unsealed_data_says_the_sealing_step_must_be_run(): void
    {
        $first = Fixtures::submit();
        Database::getInstance()->query('UPDATE document_logs SET seal = NULL');
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL');
        $backup = (new CreateBackupJob())->handle();
        $adminId = $this->fx['users']['admin'];

        (new RestoreBackupJob($backup, $adminId))->handle();

        $entry = $this->lastAuditEntry();
        $this->assertSame('backup_restored', $entry['event']);
        $this->assertSame(1, $entry['data']['unsealed_documents']);
        $this->assertTrue($entry['data']['sealing_required']);
        $notification = Database::getInstance()->query('SELECT message, type FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 1', [':u' => $adminId])->fetch();
        $this->assertSame('warning', $notification['type']);
        $this->assertStringContainsString('php scripts/seal-chains.php', $notification['message']);
        $this->assertStringContainsString('refused until', $notification['message']);
    }

    // On a database recorded as sealed, the restore notification names the reviewed-exception flag the sealing step now needs
    public function test_restoring_unsealed_data_on_a_sealed_database_names_the_flag(): void
    {
        Fixtures::submit();
        Database::getInstance()->query('UPDATE document_logs SET seal = NULL');
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL');
        $backup = (new CreateBackupJob())->handle();
        SystemAuditService::record(\App\Modules\Integrity\ChainSealService::SEALED_EVENT, ['database' => \App\Common\Config\Config::get('database', 'dbname')]);
        $adminId = $this->fx['users']['admin'];

        (new RestoreBackupJob($backup, $adminId))->handle();

        $notification = Database::getInstance()->query('SELECT message FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 1', [':u' => $adminId])->fetch();
        $this->assertStringContainsString('--seal-missing-on-sealed-database --database=', $notification['message']);
        $entry = $this->lastAuditEntry();
        $this->assertStringContainsString('--seal-missing-on-sealed-database', $entry['data']['seal_command']);
    }

    // Restoring a v1-sealed backup on an already upgraded database: the restore upgrades the restored seals itself, and v1 stays rejected elsewhere
    public function test_restoring_v1_seals_on_an_upgraded_database_is_refused_not_frozen(): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'in_transit', ['Cash Unit']);
        $db = Database::getInstance();
        $v1 = function () use ($db): void {
            foreach ($db->query('SELECT * FROM documents')->fetchAll() as $row) {
                [$newest, $count] = \App\Modules\Integrity\ChainSealService::chainHead((int) $row['id']);
                $db->query('UPDATE documents SET chain_seal = :s WHERE id = :id', [':s' => \App\Modules\Integrity\ChainSealService::chainSealV1($row, $newest, $count), ':id' => $row['id']]);
            }
        };
        $v1();
        $backup = (new CreateBackupJob())->handle();
        $databaseName = \App\Common\Config\Config::get('database', 'dbname');
        SystemAuditService::record(\App\Modules\Integrity\ChainSealService::SEALED_EVENT, ['database' => $databaseName]);
        SystemAuditService::record(\App\Modules\Integrity\ChainSealService::UPGRADED_EVENT, ['database' => $databaseName]);
        $this->assertSame(\App\Modules\Integrity\ChainSealService::UPGRADE_RECORDED, \App\Modules\Integrity\ChainSealService::upgradeState());
        $adminId = $this->fx['users']['admin'];

        (new RestoreBackupJob($backup, $adminId))->handle();

        // The restore upgraded the restored v1 seal itself and says so in the audit record and the notification
        $restored = array_values(array_filter(array_map(fn ($line) => json_decode($line, true), file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES)), fn ($entry) => $entry['event'] === 'backup_restored'))[0];
        $this->assertSame(1, $restored['data']['chain_seals_upgraded']);
        $this->assertSame(0, $restored['data']['chain_seals_left_untouched']);
        $notification = $db->query('SELECT message, type FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 1', [':u' => $adminId])->fetch();
        $this->assertStringContainsString('1 restored chain seal(s) on the old version were upgraded', $notification['message']);
        $row = $db->query('SELECT * FROM documents WHERE id = :id', [':id' => $document['id']])->fetch();
        $this->assertTrue(\App\Modules\Integrity\ChainSealService::documentSealIsValid($row));
        $this->assertTrue(\App\Modules\Integrity\PreActionIntegrityService::passes($row));

        // The upgrade stays recorded: v1 seals are still not accepted on a document that was NOT restored
        $this->assertSame(\App\Modules\Integrity\ChainSealService::UPGRADE_RECORDED, \App\Modules\Integrity\ChainSealService::upgradeState());
        $other = Fixtures::submit();
        Fixtures::advanceTo($other['id'], 'completed', ['Cash Unit']);
        $db->query('UPDATE documents SET released_at = DATE_SUB(released_at, INTERVAL 1 DAY) WHERE id = :id', [':id' => $other['id']]);
        $replayed = $db->query('SELECT * FROM documents WHERE id = :id', [':id' => $other['id']])->fetch();
        [$newest, $count] = \App\Modules\Integrity\ChainSealService::chainHead((int) $other['id']);
        $db->query('UPDATE documents SET chain_seal = :s WHERE id = :id', [':s' => \App\Modules\Integrity\ChainSealService::chainSealV1($replayed, $newest, $count), ':id' => $other['id']]);
        $replayed = $db->query('SELECT * FROM documents WHERE id = :id', [':id' => $other['id']])->fetch();

        // A mismatch (frozen), not refused as "old version" and not upgraded
        $this->assertFalse(\App\Modules\Integrity\PreActionIntegrityService::passes($replayed));
        $connection = $db->getConnection();
        $connection->beginTransaction();
        $this->assertFalse((new \App\Modules\Documents\DocumentPolicy())->process(\App\Modules\Users\UserModel::findById($adminId), $replayed));
        $connection->commit();
        $this->assertSame('frozen', $db->query('SELECT status FROM documents WHERE id = :id', [':id' => $other['id']])->fetch()['status']);
    }

    // Seals survive a backup and a restore: accents, an emoji and a slash in the title and remarks are unchanged, so every seal still verifies
    public function test_seals_survive_a_backup_round_trip_with_non_ascii_text(): void
    {
        $service = new \App\Modules\Documents\DocumentWorkflowService();
        $pin = \Tests\Support\TestDatabase::PIN;
        $document = Fixtures::submit(['title' => 'Café Ünïcode 📄 / Ωmega']);
        $records = Fixtures::user('records');
        $cash = Fixtures::user('cash');
        $service->finalizeIntake($document['id'], ['Cash Unit'], $records, $pin);
        $service->scanDocument($document['tracking_code'], $cash, $pin);
        $service->completeTask($document['id'], 'Approved / forwarded 📎 señor', $cash, $pin);
        $backup = (new CreateBackupJob())->handle();

        (new RestoreBackupJob($backup, $this->fx['users']['admin']))->handle();

        $results = \Tests\Support\IntegrityAudit::run($this->fx['users']['admin']);
        $this->assertSame(0, $results['invalid_entry_seals']);
        $this->assertSame(0, $results['invalid_chain_seals']);
        $this->assertSame(0, $results['unsealed_documents']);
        $this->assertEquals(100.0, $results['verified_percentage']);
        $this->assertSame('Café Ünïcode 📄 / Ωmega', Database::getInstance()->query('SELECT title FROM documents WHERE id = :id', [':id' => $document['id']])->fetch()['title']);
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
        $this->assertSame([], $this->workingFolders());
        $this->assertSame([], glob("{$directory}/*.sql") ?: []);
    }

    // Restoring an old backup must not let expiry delete it (or anything else) while the safety backup is made
    public function test_restoring_an_old_backup_does_not_expire_it(): void
    {
        [$backup, $first] = $this->backupThenAddDocument();
        $directory = BackupService::directory();

        // The backup to restore, and another one, are both far past the age limit
        $old = time() - 60 * 86400;
        touch("{$directory}/{$backup}", $old);
        copy("{$directory}/{$backup}", "{$directory}/another-old-backup.sql.zip");
        touch("{$directory}/another-old-backup.sql.zip", $old);

        (new RestoreBackupJob($backup, $this->fx['users']['admin']))->handle();

        $this->assertFileExists("{$directory}/{$backup}");
        $this->assertFileExists("{$directory}/another-old-backup.sql.zip");
        $this->assertTrue($this->documentExists($first['tracking_code']));
        $this->assertSame('backup_restored', $this->lastAuditEntry()['event']);
    }

    // A backup taken before the requesting admin's account existed still restores; the missing notification is not a failure
    public function test_restore_succeeds_when_the_requesting_admin_is_not_in_the_backup(): void
    {
        [$backup, $first] = $this->backupThenAddDocument();

        // An admin account created after the backup was taken
        $db = Database::getInstance();
        $db->query("INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES ('Later Admin', 'later.admin', 'x', 'admin', NOW(), NOW())");
        $laterAdminId = (int) $db->getConnection()->lastInsertId();

        (new RestoreBackupJob($backup, $laterAdminId))->handle();

        $this->assertTrue($this->documentExists($first['tracking_code']));
        $entry = $this->lastAuditEntry();
        $this->assertSame('backup_restored', $entry['event']);
        $this->assertSame($laterAdminId, $entry['data']['user_id']);
    }

    // A failed restore tells the admin too, with the reason and where the previous state was saved
    public function test_failed_restore_notifies_the_admin(): void
    {
        Fixtures::submit();
        $directory = BackupService::directory();
        file_put_contents("{$directory}/other.sql", "SELECT 1;\n");
        BackupService::archive("{$directory}/other.sql", "{$directory}/other.sql.zip", 'a different password');
        unlink("{$directory}/other.sql");
        $adminId = $this->fx['users']['admin'];

        try {
            (new RestoreBackupJob('other.sql.zip', $adminId))->handle();
        } catch (\RuntimeException $e) {
            // Expected: the archive cannot be read with the configured password
        }

        $notification = Database::getInstance()->query(
            'SELECT title, message, type FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 1',
            [':u' => $adminId]
        )->fetch();
        $this->assertSame('Backup restore failed', $notification['title']);
        $this->assertSame('error', $notification['type']);
        $this->assertStringContainsString('other.sql.zip', $notification['message']);
        $this->assertStringContainsString($this->safetyBackups()[0], $notification['message']);
    }
}
