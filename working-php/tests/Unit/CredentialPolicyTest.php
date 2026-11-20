<?php

namespace Tests\Unit;

use App\Modules\Auth\CredentialPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestFiles;

/**
 * CredentialPolicy holds the credential rules: separate password and PIN minimums and the common-password blocklist.
 */
class CredentialPolicyTest extends TestCase
{
    // Path of the common-password list, read directly to check its shape
    private const LIST_PATH = __DIR__ . '/../../src/Modules/Auth/data/common-passwords.txt';

    // Temp list files created by a test, deleted in tearDown
    private array $tempFiles = [];

    // Delete the temp files
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    // Create a temp list file with the given contents and return its path
    private function tempListFile(string $contents): string
    {
        $path = TestFiles::file('cplist');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    // Lines of the list file, split into header lines (start with #) and entries (non-blank)
    private function listLines(): array
    {
        $header = [];
        $entries = [];

        $this->assertFileExists(self::LIST_PATH);

        // Sort each line into header or entry, skipping blank lines
        foreach (file(self::LIST_PATH, FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                $header[] = $line;
                continue;
            }
            $entries[] = $line;
        }

        return ['header' => $header, 'entries' => $entries];
    }

    // A password needs 15 characters, a PIN 10, counted in characters not bytes
    public function test_password_and_pin_minimums(): void
    {
        $this->assertSame(15, CredentialPolicy::MIN_LENGTH_PASSWORD);
        $this->assertSame(10, CredentialPolicy::MIN_LENGTH_PIN);
        $this->assertFalse(CredentialPolicy::isLongEnough(str_repeat('x', 14), 15));
        $this->assertTrue(CredentialPolicy::isLongEnough(str_repeat('x', 15), 15));
        $this->assertTrue(CredentialPolicy::isLongEnough(str_repeat('é', 15), 15));
    }

    // The message names the label and the given minimum
    public function test_too_short_message_names_label_and_minimum(): void
    {
        $this->assertSame(
            'The new password must be at least 15 characters.',
            CredentialPolicy::tooShortMessage('password', 15)
        );
    }

    // The list has exactly 3,000 distinct lower-case entries
    public function test_list_has_3000_distinct_lower_case_entries(): void
    {
        $entries = $this->listLines()['entries'];

        $this->assertCount(3000, $entries);
        $this->assertCount(3000, array_unique($entries));

        // Every entry equals its lower-cased form
        foreach ($entries as $entry) {
            $this->assertSame(mb_strtolower($entry), $entry);
        }
    }

    // The header names the source, the pinned commit and the licence
    public function test_list_header_names_source_and_licence(): void
    {
        $header = implode("\n", $this->listLines()['header']);

        $this->assertStringContainsString('SecLists', $header);
        $this->assertStringContainsString('f90961c9c5be42d80e2f1b6e27b968530918862d', $header);
        $this->assertStringContainsString('MIT', $header);
    }

    // Matching ignores case
    public function test_common_passwords_are_found_case_insensitively(): void
    {
        $this->assertTrue(CredentialPolicy::isCommonPassword('123456'));
        $this->assertTrue(CredentialPolicy::isCommonPassword('PASSWORD'));
        $this->assertTrue(CredentialPolicy::isCommonPassword('PolniyPizdec0211'));
    }

    // Only whole-line matches count
    public function test_uncommon_and_containing_passwords_are_not_found(): void
    {
        $this->assertFalse(CredentialPolicy::isCommonPassword('Fixture-Pass-2026'));
        $this->assertFalse(CredentialPolicy::isCommonPassword('my-123456-is-long-enough'));
        $this->assertFalse(CredentialPolicy::isCommonPassword(''));
    }

    // A missing list file throws instead of meaning "no common passwords"
    public function test_missing_list_file_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        CredentialPolicy::loadCommonPasswords(TestFiles::file('cplist-missing'));
    }

    // A list file with no entries (only header and blank lines) throws
    public function test_list_without_entries_throws(): void
    {
        $path = $this->tempListFile("# header only\n\n");

        $this->expectException(\RuntimeException::class);

        CredentialPolicy::loadCommonPasswords($path);
    }

    // Header and blank lines are skipped, \r is stripped, and non-ASCII entries match after lower-casing
    public function test_loader_skips_headers_and_matches_non_ascii_case_insensitively(): void
    {
        $path = $this->tempListFile("# header\r\n\r\nécole\r\nsecret\n");
        $set = CredentialPolicy::loadCommonPasswords($path);

        $this->assertSame(['école' => true, 'secret' => true], $set);
        $this->assertArrayHasKey(mb_strtolower('ÉCOLE'), $set);
    }
}
