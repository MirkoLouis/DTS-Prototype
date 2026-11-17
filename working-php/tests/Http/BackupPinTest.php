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

    // The test backup's bytes, as a download must serve them
    private function backupBytes(): string
    {
        return (string) file_get_contents(BackupService::directory() . '/' . self::FILE);
    }

    // Downloading without a PIN is refused: no file is served and nothing is recorded
    public function test_download_without_a_pin_is_refused(): void
    {
        $client = $this->admin();
        $response = $client->post('/system/backups/download/' . self::FILE, []);

        $this->assertNotSame($this->backupBytes(), $response['body']);
        $this->assertSame(302, $response['status']);
        $this->assertSame('/system/backups', $response['headers']['location']);
        $this->assertStringContainsString('Security PIN is required.', $client->get('/system/backups')['body']);
        $this->assertSame([], $this->auditEvents());
    }

    // Downloading with a wrong PIN is refused: no file is served and nothing is recorded
    public function test_download_with_a_wrong_pin_is_refused(): void
    {
        $client = $this->admin();
        $response = $client->post('/system/backups/download/' . self::FILE, ['pin' => '000000']);

        $this->assertNotSame($this->backupBytes(), $response['body']);
        $this->assertSame(302, $response['status']);
        $this->assertSame('/system/backups', $response['headers']['location']);
        $this->assertMatchesRegularExpression('/bg-red-100[^>]*>\s*\S/', $client->get('/system/backups')['body']);
        $this->assertSame([], $this->auditEvents());
    }

    // Downloading with the right PIN serves the backup and records who downloaded it
    public function test_download_with_the_right_pin_serves_the_backup_and_is_recorded(): void
    {
        $client = $this->admin();
        $response = $client->post('/system/backups/download/' . self::FILE, ['pin' => TestDatabase::PIN]);

        $this->assertSame($this->backupBytes(), $response['body']);
        $events = $this->auditEvents();
        $this->assertCount(1, $events);
        $this->assertSame('backup_downloaded', $events[0]['event']);
        $this->assertSame(['file' => self::FILE, 'user_id' => $this->fx['users']['admin']], $events[0]['data']);
    }

    // A link alone no longer serves a backup: the old GET route does not return the file
    public function test_download_link_alone_does_not_serve_the_backup(): void
    {
        $client = $this->admin();
        $response = $client->get('/system/backups/download/' . self::FILE);

        $this->assertSame(404, $response['status']);
        $this->assertNotSame($this->backupBytes(), $response['body']);
        $this->assertSame([], $this->auditEvents());
    }

    // The backups page asks for the PIN on the download too
    public function test_backups_page_download_form_asks_for_the_pin(): void
    {
        $body = $this->admin()->get('/system/backups')['body'];

        $this->assertMatchesRegularExpression('/action="\/system\/backups\/download\/' . preg_quote(self::FILE, '/') . '"[^>]*class="[^"]*sign-action/', $body);

        // The download form carries the CSRF token and the hidden field the signing modal fills with the PIN
        preg_match('/<form action="\/system\/backups\/download\/[^"]*".*?<\/form>/s', $body, $form);
        $this->assertStringContainsString('name="csrf_token"', $form[0] ?? '');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="pin" class="sign-pin-input">/', $form[0] ?? '');
    }

    // MAX_FAILURES wrong PINs lock out the next download, even with the right PIN
    public function test_too_many_wrong_pins_lock_out_the_next_download(): void
    {
        $client = $this->admin();
        for ($i = 0; $i < \App\Modules\Auth\AttemptThrottleService::MAX_FAILURES; $i++) {
            $client->post('/system/backups/download/' . self::FILE, ['pin' => '000000']);
            $client->get('/system/backups');
        }
        $response = $client->post('/system/backups/download/' . self::FILE, ['pin' => TestDatabase::PIN]);

        $this->assertNotSame($this->backupBytes(), $response['body']);
        $this->assertSame([], $this->auditEvents());
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

    // MAX_FAILURES wrong PINs lock the admin out, so even the right PIN is refused for a while
    public function test_too_many_wrong_pins_lock_out_the_next_attempt(): void
    {
        $client = $this->admin();
        for ($i = 0; $i < \App\Modules\Auth\AttemptThrottleService::MAX_FAILURES; $i++) {
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

    // A backup whose name has a space and a "+": Download, Restore and Delete are addressed with the same encoded name
    // (built by PHP, urlencode) and each reaches that file
    public function test_backup_name_with_space_and_plus_is_addressed_the_same_by_all_three_actions(): void
    {
        $directory = BackupService::directory();
        $odd = 'odd name+1.sql.zip';
        file_put_contents("{$directory}/odd.sql", "SELECT 1;\n");
        BackupService::archive("{$directory}/odd.sql", "{$directory}/{$odd}");
        unlink("{$directory}/odd.sql");

        $client = $this->admin();
        $body = $client->get('/system/backups')['body'];
        $encoded = urlencode($odd);

        // The three addresses of the row all end with the same encoded name
        $this->assertSame(1, preg_match('#data-restore-file-name="' . preg_quote(htmlspecialchars($odd, ENT_QUOTES), '#') . '" data-restore-url="([^"]+)"#', $body, $restore));
        $this->assertSame('/system/backups/restore/' . $encoded, $restore[1]);
        $this->assertStringContainsString('action="/system/backups/download/' . $encoded . '"', $body);
        $this->assertStringContainsString('action="/system/backups/delete/' . $encoded . '"', $body);

        // Each address reaches the file: restore queues a job for it, download serves it, delete removes it
        $client->post($restore[1], ['pin' => TestDatabase::PIN]);
        $this->assertStringContainsString(json_encode($odd), implode(' ', $this->queuedJobs()));
        // (a page between the requests gives the client a current CSRF token to send)
        $client->get('/system/backups');
        $this->assertSame('application/zip', $client->post('/system/backups/download/' . $encoded, ['pin' => TestDatabase::PIN])['headers']['content-type'] ?? '');
        $client->get('/system/backups');
        $client->post('/system/backups/delete/' . $encoded, ['pin' => TestDatabase::PIN]);
        $this->assertFileDoesNotExist("{$directory}/{$odd}");
    }

    // The PIN prompt and the elements its behaviour attaches to are part of the page content, so in-app navigation
    // (which swaps only #pjax-content) brings them along; the behaviour itself is in script files that the layout
    // loads once (components/sign-actions.js for the PIN prompt of the forms, pages/backups.js for the Restore button)
    public function test_pin_prompt_is_inside_the_page_content(): void
    {
        $body = $this->admin()->get('/system/backups')['body'];

        // The container the router swaps ends where <main> ends
        $start = strpos($body, '<div id="pjax-content">');
        $end = strpos($body, '</main>', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        // The prompt, the restore dialog, its trigger and the PIN-asking forms are all inside the swapped content
        foreach (['id="signing-modal"', 'id="signing-modal-confirm"', 'id="restore-modal"', 'class="restore-backup-trigger', 'id="restore-form"', 'class="sign-action', 'class="sign-pin-input"'] as $needle) {
            $position = strpos($body, $needle);
            $this->assertNotFalse($position, $needle);
            $this->assertGreaterThan($start, $position, $needle);
            $this->assertLessThan($end, $position, $needle);
        }
        $this->assertMatchesRegularExpression('/class="restore-backup-trigger[^"]*"[^>]*data-restore-file-name="' . preg_quote(self::FILE, '/') . '"/', $body);

        // Nothing after the closing html tag, and the behaviour comes from script files in the head
        $this->assertFalse(strpos($body, 'SigningModal', (int) strpos($body, '</html>')), 'the signing modal is emitted after the page');
        foreach (['/js/components/signing-modal.js', '/js/components/sign-actions.js', '/js/pages/backups.js'] as $script) {
            $this->assertLessThan(strpos($body, '</head>'), strpos($body, '<script src="' . $script . '" defer></script>'), $script);
        }
    }
}
