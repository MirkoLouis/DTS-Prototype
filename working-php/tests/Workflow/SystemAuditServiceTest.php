<?php

namespace Tests\Workflow;

use App\Common\Config\Config;
use App\Modules\System\SystemAuditService;
use Tests\TestCase;

/**
 * The system audit log records events outside the database, and any change to what was written is detected.
 */
class SystemAuditServiceTest extends TestCase
{
    // Every test starts with no audit log
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(SystemAuditService::path());
    }

    // The lines of the audit log as decoded entries
    private function entries(): array
    {
        return array_map(
            fn($line) => json_decode($line, true),
            file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
    }

    // Replace the audit log with the given entries, written the way the service writes them
    private function rewrite(array $entries): void
    {
        $lines = array_map(fn($entry) => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $entries);
        file_put_contents(SystemAuditService::path(), implode("\n", $lines) . "\n");
    }

    // Write three events
    private function recordThree(): void
    {
        SystemAuditService::record('backup_requested', ['user_id' => 1]);
        SystemAuditService::record('backup_deleted', ['file' => 'a.sql.zip', 'user_id' => 1]);
        SystemAuditService::record('backup_restored', ['file' => 'b.sql.zip', 'user_id' => 1]);
    }

    // Under the test suite the log lives in the .test folder
    public function test_log_uses_the_test_folder_when_testing(): void
    {
        $this->assertSame(Config::testPath('logs/system-audit.log'), SystemAuditService::path());
    }

    // Each entry carries the previous entry's mac, and an untouched log verifies
    public function test_entries_are_chained_and_verify(): void
    {
        $this->recordThree();
        $entries = $this->entries();

        $this->assertCount(3, $entries);
        $this->assertSame('', $entries[0]['prev']);
        $this->assertSame($entries[0]['mac'], $entries[1]['prev']);
        $this->assertSame($entries[1]['mac'], $entries[2]['prev']);
        $this->assertSame('backup_deleted', $entries[1]['event']);
        $this->assertSame(['file' => 'a.sql.zip', 'user_id' => 1], $entries[1]['data']);

        $this->assertSame(['keyed' => true, 'entries' => 3, 'bad_lines' => []], SystemAuditService::verify());
    }

    // No log yet is an intact, empty log
    public function test_missing_log_verifies_as_empty(): void
    {
        $this->assertSame(['keyed' => true, 'entries' => 0, 'bad_lines' => []], SystemAuditService::verify());
    }

    // Changing what an entry says is detected on that line
    public function test_edited_line_is_detected(): void
    {
        $this->recordThree();
        $entries = $this->entries();
        $entries[1]['data']['file'] = 'other.sql.zip';
        $this->rewrite($entries);

        $this->assertSame([2], SystemAuditService::verify()['bad_lines']);
    }

    // Removing an entry from the middle breaks the link of the entry after it
    public function test_removed_middle_line_is_detected(): void
    {
        $this->recordThree();
        $entries = $this->entries();
        $this->rewrite([$entries[0], $entries[2]]);

        $this->assertSame([2], SystemAuditService::verify()['bad_lines']);
    }

    // Swapping two entries is detected
    public function test_reordered_lines_are_detected(): void
    {
        $this->recordThree();
        $entries = $this->entries();
        $this->rewrite([$entries[1], $entries[0], $entries[2]]);

        $this->assertNotSame([], SystemAuditService::verify()['bad_lines']);
    }

    // Someone without the key cannot make an edited entry verify by recomputing an ordinary hash
    public function test_recomputing_a_plain_hash_does_not_pass_a_keyed_log(): void
    {
        $this->recordThree();
        $entries = $this->entries();

        // Edit the last entry and give it the unkeyed SHA-256 of its new contents
        $entries[2]['data']['file'] = 'forged.sql.zip';
        $unsigned = $entries[2];
        unset($unsigned['mac']);
        $entries[2]['mac'] = hash('sha256', json_encode($unsigned, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->rewrite($entries);

        $this->assertSame([3], SystemAuditService::verify()['bad_lines']);
    }

    // A line that is not JSON is reported, and the entries after it are still checked
    public function test_garbage_line_is_reported(): void
    {
        $this->recordThree();
        file_put_contents(SystemAuditService::path(), "not json\n", FILE_APPEND);

        $this->assertSame([4], SystemAuditService::verify()['bad_lines']);
    }

    // An audit write that cannot happen is logged, never thrown at the caller
    public function test_record_never_throws_when_the_file_cannot_be_written(): void
    {
        // A directory where the log file should be makes every write fail
        mkdir(SystemAuditService::path(), 0777, true);

        try {
            SystemAuditService::record('backup_requested', ['user_id' => 1]);
            $this->assertDirectoryExists(SystemAuditService::path());
        } finally {
            rmdir(SystemAuditService::path());
        }
    }

    // Text that is not valid UTF-8 (a file name, a tool's error message) is still recorded, never dropped
    public function test_invalid_utf8_data_is_still_recorded(): void
    {
        SystemAuditService::record('backup_restore_failed', ['reason' => "bad byte \xB1 here"]);

        $entries = $this->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('backup_restore_failed', $entries[0]['event']);
        $this->assertSame([], SystemAuditService::verify()['bad_lines']);
    }

    // After a write that was cut off mid-line, the next entry starts on its own line and verifies
    public function test_entry_after_a_cut_off_line_starts_on_its_own_line(): void
    {
        $this->recordThree();
        file_put_contents(SystemAuditService::path(), '{"ts":"cut off', FILE_APPEND);
        SystemAuditService::record('backup_requested', ['user_id' => 1]);

        // Five lines: three good, the cut-off one, and the new entry, which is intact
        $this->assertSame(['keyed' => true, 'entries' => 5, 'bad_lines' => [4]], SystemAuditService::verify());
    }
}
