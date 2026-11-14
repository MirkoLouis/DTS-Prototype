<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Modules\System\BackupService;
use App\Modules\System\Jobs\RestoreBackupJob;
use App\Modules\System\SystemAuditService;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Restoring or deleting a backup needs the admin's Security PIN, and each backup action is recorded.
 */
class BackupPinTest extends TestCase
{
    private const FILE = 'pin-test.sql.zip';

    // One backup archive in an otherwise empty backup folder, and no audit log
    protected function setUp(): void
    {
        parent::setUp();
        $directory = BackupService::directory();
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        file_put_contents("{$directory}/pin-test.sql", "SELECT 1;\n");
        BackupService::archive("{$directory}/pin-test.sql", "{$directory}/" . self::FILE);
        unlink("{$directory}/pin-test.sql");
        @unlink(SystemAuditService::path());
    }

    // Sign in as the admin on the backups page so the CSRF token is current
    private function admin(): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->get('/system/backups');

        return $client;
    }

    // Whether the test backup is still in the backup folder
    private function backupExists(): bool
    {
        return is_file(BackupService::directory() . '/' . self::FILE);
    }

    // Events in the audit log, oldest first
    private function auditEvents(): array
    {
        if (!is_file(SystemAuditService::path())) {
            return [];
        }

        return array_map(fn($line) => json_decode($line, true), file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    // Jobs waiting in the queue
    private function queuedJobs(): array
    {
        return Database::getInstance()->query('SELECT payload FROM jobs ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    // Deleting without a PIN is refused and the backup stays
    public function test_delete_without_a_pin_is_refused(): void
    {
        $client = $this->admin();
        $client->post('/system/backups/delete/' . self::FILE, []);

        $this->assertTrue($this->backupExists());
        $this->assertStringContainsString('Security PIN', $client->get('/system/backups')['body']);
        $this->assertSame([], $this->auditEvents());
    }

    // Deleting with a wrong PIN is refused and the backup stays
    public function test_delete_with_a_wrong_pin_is_refused(): void
    {
        $client = $this->admin();
        $client->post('/system/backups/delete/' . self::FILE, ['pin' => '000000']);

        $this->assertTrue($this->backupExists());
        $this->assertSame([], $this->auditEvents());
    }

    // Deleting with the right PIN removes the backup and records who did it
    public function test_delete_with_the_right_pin_removes_the_backup_and_is_recorded(): void
    {
        $client = $this->admin();
        $client->post('/system/backups/delete/' . self::FILE, ['pin' => TestDatabase::PIN]);

        $this->assertFalse($this->backupExists());
        $events = $this->auditEvents();
        $this->assertCount(1, $events);
        $this->assertSame('backup_deleted', $events[0]['event']);
        $this->assertSame(['file' => self::FILE, 'user_id' => $this->fx['users']['admin']], $events[0]['data']);
        $this->assertStringContainsString('Backup ' . self::FILE . ' deleted.', $client->get('/system/backups')['body']);
    }

    // Restoring without a PIN queues nothing
    public function test_restore_without_a_pin_queues_nothing(): void
    {
        $client = $this->admin();
        $client->post('/system/backups/restore/' . self::FILE, []);

        $this->assertSame([], $this->queuedJobs());
        $this->assertSame([], $this->auditEvents());
    }

    // Restoring with the right PIN queues the restore for that admin and records the request
    public function test_restore_with_the_right_pin_queues_the_restore_and_is_recorded(): void
    {
        $client = $this->admin();
        $client->post('/system/backups/restore/' . self::FILE, ['pin' => TestDatabase::PIN]);

        $jobs = $this->queuedJobs();
        $this->assertCount(1, $jobs);
        $payload = json_decode($jobs[0], true);
        $this->assertSame(RestoreBackupJob::class, $payload['class']);
        $this->assertSame([self::FILE, $this->fx['users']['admin']], $payload['data']);

        $events = $this->auditEvents();
        $this->assertSame('backup_restore_requested', $events[0]['event']);
        $this->assertSame(['file' => self::FILE, 'user_id' => $this->fx['users']['admin']], $events[0]['data']);
    }

    // A backup that does not exist cannot be restored or deleted, even with the right PIN
    public function test_missing_backup_is_refused_with_the_right_pin(): void
    {
        $client = $this->admin();
        $client->post('/system/backups/restore/no-such-file.sql.zip', ['pin' => TestDatabase::PIN]);
        $client->post('/system/backups/delete/no-such-file.sql.zip', ['pin' => TestDatabase::PIN]);

        $this->assertSame([], $this->queuedJobs());
        $this->assertSame([], $this->auditEvents());
    }

    // Three wrong PINs lock the admin out, so even the right PIN is refused for a while
    public function test_three_wrong_pins_lock_out_the_next_attempt(): void
    {
        $client = $this->admin();
        for ($i = 0; $i < 3; $i++) {
            $client->post('/system/backups/delete/' . self::FILE, ['pin' => '000000']);
            $client->get('/system/backups');
        }
        $client->post('/system/backups/delete/' . self::FILE, ['pin' => TestDatabase::PIN]);

        $this->assertTrue($this->backupExists());
    }

    // Creating a backup needs no PIN; the request is queued and recorded
    public function test_backup_request_is_recorded(): void
    {
        $client = $this->admin();
        $client->post('/system/backups/create', []);

        $this->assertCount(1, $this->queuedJobs());
        $events = $this->auditEvents();
        $this->assertSame('backup_requested', $events[0]['event']);
        $this->assertSame(['user_id' => $this->fx['users']['admin']], $events[0]['data']);
    }

    // The backups page asks for the PIN on both destructive actions
    public function test_backups_page_forms_ask_for_the_pin(): void
    {
        $body = $this->admin()->get('/system/backups')['body'];

        $this->assertMatchesRegularExpression('/action="\/system\/backups\/delete\/' . preg_quote(self::FILE, '/') . '"[^>]*class="[^"]*sign-action/', $body);
        $this->assertMatchesRegularExpression('/id="restore-form"[^>]*class="[^"]*sign-action/', $body);
        $this->assertStringContainsString('sign-pin-input', $body);
    }

    // The PIN prompt and its wiring are part of the page content, so in-app navigation (which swaps only
    // the content) brings them along; anything after the closing html tag would be dropped
    public function test_pin_prompt_is_inside_the_page_content(): void
    {
        $body = $this->admin()->get('/system/backups')['body'];
        $end = strpos($body, '</html>');

        $this->assertNotFalse($end);
        $this->assertNotFalse(strpos($body, 'initBackupsPage'));
        $this->assertLessThan($end, strpos($body, 'initBackupsPage'));
        $this->assertFalse(strpos($body, 'SigningModal', $end), 'the signing modal is emitted after the page');
    }
}
