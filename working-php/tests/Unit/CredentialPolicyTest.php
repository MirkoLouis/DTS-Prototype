<?php

namespace Tests\Unit;

use App\Modules\Auth\CredentialPolicy;
use PHPUnit\Framework\TestCase;

/**
 * CredentialPolicy holds the credential rules: separate password and PIN minimums and the common-password blocklist.
 */
class CredentialPolicyTest extends TestCase
{
    // Path of the common-password list, read directly to check its shape
    private const LIST_PATH = __DIR__ . '/../../src/Modules/Auth/data/common-passwords.txt';

    // Lines of the list file, split into header lines (start with #) and entries (non-blank)
    private function listLines(): array
    {
        $header = [];
        $entries = [];

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
}
