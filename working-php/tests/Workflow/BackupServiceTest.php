<?php

namespace Tests\Workflow;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Modules\System\BackupService;
use App\Modules\System\Jobs\CreateBackupJob;
use App\Modules\System\Jobs\RestoreBackupJob;
use Tests\Support\Fixtures;
use Tests\Support\TestFiles;
use Tests\TestCase;

/**
 * Backups: where they are stored, what they are called, what they leave out, and when they expire.
 */
class BackupServiceTest extends TestCase
{
    private const DAY = 86400;
    private const NOW = 1_800_000_000;

    // Start every test with an empty test backup folder
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(BackupService::directory() . '/*') ?: [] as $file) {
            unlink($file);
        }
    }

    // Create an empty zip file aged the given number of days
    private function agedBackup(string $directory, string $name, int $days): string
    {
        $path = "{$directory}/{$name}.zip";
        touch($path, self::NOW - $days * self::DAY);

        return basename($path);
    }

    // The SQL text inside a backup zip
    private function sqlOf(string $zipName): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(BackupService::directory() . '/' . $zipName));
        $zip->setPassword((string) Config::get('app', 'backup_password', ''));
        $sql = (string) $zip->getFromIndex(0);
        $zip->close();

        return $sql;
    }

    // Under the test suite, backups go to the .test folder
    public function test_backups_use_the_test_folder_when_testing(): void
    {
        $this->assertSame(Config::testPath('backups'), BackupService::directory());
        $this->assertDirectoryExists(BackupService::directory());
    }

    // The file name starts with the database it was taken from
    public function test_file_name_carries_the_database_name(): void
    {
        $at = new \DateTimeImmutable('2026-03-05 08:15:30');

        $this->assertSame('deped_dts_test_2026_03_05_081530_12.sql', BackupService::fileName('deped_dts_test', 12, $at));
    }

    // Backups older than the limit are deleted; the newest is kept, and so are those inside the limit
    public function test_backups_older_than_the_limit_are_deleted_except_the_newest(): void
    {
        $dir = TestFiles::dir('expire');
        $twenty = $this->agedBackup($dir, 'twenty', 20);
        $fifteen = $this->agedBackup($dir, 'fifteen', 15);
        $thirteen = $this->agedBackup($dir, 'thirteen', 13);
        $one = $this->agedBackup($dir, 'one', 1);

        $deleted = BackupService::expire($dir, 14, self::NOW);
        sort($deleted);

        $this->assertSame([$fifteen, $twenty], $deleted);
        $this->assertFileExists("{$dir}/{$thirteen}");
        $this->assertFileExists("{$dir}/{$one}");
    }

    // The only backup is kept even when it is older than the limit, so one always exists
    public function test_the_only_backup_is_kept_even_when_it_is_old(): void
    {
        $dir = TestFiles::dir('expire-one');
        $only = $this->agedBackup($dir, 'only', 40);

        $this->assertSame([], BackupService::expire($dir, 14, self::NOW));
        $this->assertFileExists("{$dir}/{$only}");
    }

    // A limit of zero days turns expiry off
    public function test_zero_days_disables_expiry(): void
    {
        $dir = TestFiles::dir('expire-off');
        $this->agedBackup($dir, 'old', 400);
        $this->agedBackup($dir, 'new', 1);

        $this->assertSame([], BackupService::expire($dir, 0, self::NOW));
        $this->assertCount(2, glob("{$dir}/*.zip"));
    }

    // Archiving a dump gives a zip holding that one file
    public function test_archive_zips_the_dump(): void
    {
        $dir = TestFiles::dir('archive');
        file_put_contents("{$dir}/dump.sql", "SELECT 1;\n");
        BackupService::archive("{$dir}/dump.sql", "{$dir}/dump.sql.zip");

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open("{$dir}/dump.sql.zip"));
        $this->assertSame('dump.sql', $zip->getNameIndex(0));
        $this->assertSame("SELECT 1;\n", $zip->getFromIndex(0));
        $zip->close();
    }

    // An archive that cannot be written throws and leaves no zip behind
    public function test_archive_failure_throws_and_leaves_no_zip(): void
    {
        $dir = TestFiles::dir('archive-fail');

        // The dump to archive does not exist, so the zip cannot be completed
        try {
            BackupService::archive("{$dir}/missing.sql", "{$dir}/missing.sql.zip");
            $this->fail('Archiving a missing dump did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('backup archive', $e->getMessage());
        }

        $this->assertFileDoesNotExist("{$dir}/missing.sql.zip");
    }

    // A backup holds the documents and their logs, and leaves the queue and cache tables out
    public function test_backup_excludes_queue_and_cache_tables(): void
    {
        Fixtures::submit();
        $zipName = (new CreateBackupJob())->handle();

        $this->assertStringStartsWith('deped_dts_test_', $zipName);
        $this->assertFileExists(BackupService::directory() . '/' . $zipName);

        $sql = $this->sqlOf($zipName);
        $this->assertStringContainsString('CREATE TABLE `documents`', $sql);
        $this->assertStringContainsString('CREATE TABLE `document_logs`', $sql);
        foreach (BackupService::EXCLUDED_TABLES as $table) {
            $this->assertStringNotContainsString("CREATE TABLE `{$table}`", $sql);
        }
        $this->assertStringNotContainsString('mysqldump:', $sql);
    }

    // Restoring a backup brings deleted data back and does not touch the job queue
    public function test_restore_brings_back_deleted_data_and_leaves_the_queue_alone(): void
    {
        // Back up one document, then lose it and queue a job
        $document = Fixtures::submit();
        $zipName = (new CreateBackupJob())->handle();
        $db = Database::getInstance();
        $db->query('DELETE FROM document_logs WHERE document_id = :id', [':id' => $document['id']]);
        $db->query('DELETE FROM documents WHERE id = :id', [':id' => $document['id']]);
        $db->query("INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', '{}', 0, 0, 0)");

        (new RestoreBackupJob($zipName))->handle();

        // The document and its log are back; the queued job is still there
        $this->assertNotFalse($db->query('SELECT 1 FROM documents WHERE tracking_code = :c', [':c' => $document['tracking_code']])->fetch());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id', [':id' => $document['id']])->fetch()['c']);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) c FROM jobs')->fetch()['c']);
    }

    // A new backup removes backups past the age limit from the backup folder
    public function test_new_backup_expires_old_ones(): void
    {
        $old = $this->agedBackup(BackupService::directory(), 'deped_dts_test_old', 30);
        touch(BackupService::directory() . '/' . $old, time() - 30 * self::DAY);

        $zipName = (new CreateBackupJob())->handle();

        $this->assertFileDoesNotExist(BackupService::directory() . '/' . $old);
        $this->assertFileExists(BackupService::directory() . '/' . $zipName);
    }

    // A dump that fails leaves neither an SQL file nor a zip that could be mistaken for a backup
    public function test_failed_dump_leaves_no_backup_file(): void
    {
        // A backup job whose dump is given an option mysqldump rejects
        $job = new class extends CreateBackupJob {
            protected function dumpArguments(string $database): array
            {
                return ['--no-such-option'];
            }
        };

        try {
            $job->handle();
            $this->fail('A failing dump did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mysqldump failed', $e->getMessage());
        }

        $this->assertSame([], glob(BackupService::directory() . '/*') ?: []);
    }

    // Write a small dump and archive it, optionally encrypted; returns [directory, zip path]
    private function archived(string $password, string $entryName = 'dump.sql'): array
    {
        $dir = TestFiles::dir('encrypt');
        file_put_contents("{$dir}/{$entryName}", "SELECT 42;\n");
        BackupService::archive("{$dir}/{$entryName}", "{$dir}/backup.zip", $password);
        unlink("{$dir}/{$entryName}");

        return [$dir, "{$dir}/backup.zip"];
    }

    // An encrypted archive gives nothing to someone who opens it without the password
    public function test_encrypted_archive_cannot_be_read_without_the_password(): void
    {
        [, $zipPath] = $this->archived('correct horse');

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $this->assertSame(\ZipArchive::EM_AES_256, $zip->statIndex(0)['encryption_method']);
        $this->assertFalse(@$zip->getFromIndex(0));
        $zip->close();
    }

    // With the password, the dump is extracted next to the archive
    public function test_encrypted_archive_extracts_with_the_password(): void
    {
        [$dir, $zipPath] = $this->archived('correct horse');

        $sqlPath = BackupService::extract($zipPath, $dir, 'correct horse');
        $this->assertSame("{$dir}/dump.sql", $sqlPath);
        $this->assertSame("SELECT 42;\n", file_get_contents($sqlPath));
    }

    // The wrong password fails with a message that names the cause, and leaves no file behind
    public function test_wrong_password_fails_with_a_clear_message_and_leaves_no_file(): void
    {
        [$dir, $zipPath] = $this->archived('correct horse');

        try {
            BackupService::extract($zipPath, $dir, 'wrong password');
            $this->fail('Extracting with the wrong password did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('BACKUP_PASSWORD', $e->getMessage());
        }

        $this->assertFileDoesNotExist("{$dir}/dump.sql");
    }

    // A backup made before encryption was turned on still restores once a password is set
    public function test_unencrypted_archive_still_extracts_when_a_password_is_set(): void
    {
        [$dir, $zipPath] = $this->archived('');

        $this->assertSame("SELECT 42;\n", file_get_contents(BackupService::extract($zipPath, $dir, 'correct horse')));
    }

    // An archive whose entry would be written outside the backup folder is refused
    public function test_archive_entry_outside_the_folder_is_rejected(): void
    {
        $dir = TestFiles::dir('zip-slip');
        $zip = new \ZipArchive();
        $zip->open("{$dir}/crafted.zip", \ZipArchive::CREATE);
        $zip->addFromString('../evil.sql', "DROP DATABASE x;\n");
        $zip->close();

        try {
            BackupService::extract("{$dir}/crafted.zip", $dir);
            $this->fail('A crafted archive was extracted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unexpected contents', $e->getMessage());
        }

        $this->assertFileDoesNotExist(dirname($dir) . '/evil.sql');
    }

    // An archive that holds something other than one .sql file is refused
    public function test_archive_entry_that_is_not_sql_is_rejected(): void
    {
        $dir = TestFiles::dir('zip-other');
        $zip = new \ZipArchive();
        $zip->open("{$dir}/crafted.zip", \ZipArchive::CREATE);
        $zip->addFromString('shell.php', '<?php');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected contents');
        BackupService::extract("{$dir}/crafted.zip", $dir);
    }

    // A backup made by the job is encrypted with the configured password
    public function test_job_encrypts_the_backup_with_the_configured_password(): void
    {
        $this->assertNotSame('', (string) Config::get('app', 'backup_password', ''), 'BACKUP_PASSWORD must be set in .env.testing');
        Fixtures::submit();
        $zipName = (new CreateBackupJob())->handle();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(BackupService::directory() . '/' . $zipName));
        $this->assertSame(\ZipArchive::EM_AES_256, $zip->statIndex(0)['encryption_method']);
        $zip->close();
    }

    // Without a backup password the admin is told that backups are stored unencrypted; with one, nothing is shown
    public function test_missing_backup_password_gives_a_warning(): void
    {
        $this->assertNull(BackupService::encryptionWarning('correct horse'));

        $warning = BackupService::encryptionWarning('');
        $this->assertStringContainsString('BACKUP_PASSWORD', $warning);
        $this->assertStringContainsString('unencrypted', $warning);
    }

    // The backups page shows no warning while a backup password is configured
    public function test_backups_page_shows_no_warning_when_a_password_is_set(): void
    {
        $client = \Tests\Support\HttpClient::start();
        $client->loginAs('admin');
        $response = $client->get('/system/backups');

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('stored unencrypted', $response['body']);
    }
}
