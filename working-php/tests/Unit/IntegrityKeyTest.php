<?php

namespace Tests\Unit;

use App\Common\Security\IntegrityKey;
use App\Common\Security\IntegrityKeyMissingException;
use PHPUnit\Framework\TestCase;

/**
 * The shared integrity-key helper: strict base64 of exactly 32 bytes, nothing else counts as configured.
 */
class IntegrityKeyTest extends TestCase
{
    // Leave no override behind
    protected function tearDown(): void
    {
        putenv(IntegrityKey::TEST_OVERRIDE_VARIABLE);
        parent::tearDown();
    }

    // Set the key text the helper reads (test seam)
    private function useKey(string $encoded): void
    {
        putenv(IntegrityKey::TEST_OVERRIDE_VARIABLE . '=' . $encoded);
    }

    // A base64 key of exactly 32 bytes is accepted and decoded
    public function test_valid_key_is_decoded(): void
    {
        $this->useKey(base64_encode(str_repeat("\x01", 32)));

        $this->assertTrue(IntegrityKey::isConfigured());
        $this->assertSame(str_repeat("\x01", 32), IntegrityKey::raw());
        $this->assertSame(hash_hmac('sha256', 'payload', str_repeat("\x01", 32)), IntegrityKey::hmac('payload'));
    }

    // Empty, not base64, and the wrong length all count as "not configured"
    public function test_missing_or_invalid_keys_are_not_configured(): void
    {
        foreach (['', 'not base64 !!', base64_encode(str_repeat('a', 31)), base64_encode(str_repeat('a', 33))] as $bad) {
            $this->useKey($bad);
            $this->assertFalse(IntegrityKey::isConfigured(), "'{$bad}'");
            $this->assertNull(IntegrityKey::raw());
        }
    }

    // require() and hmac() refuse without a key, with the operator-facing message
    public function test_require_throws_without_a_key(): void
    {
        $this->useKey('');

        $this->expectException(IntegrityKeyMissingException::class);
        $this->expectExceptionMessage(IntegrityKey::USER_MESSAGE);
        IntegrityKey::hmac('payload');
    }

    // Outside APP_ENV=testing the override variable is ignored (a probe process reading the real configuration)
    public function test_override_is_ignored_outside_testing(): void
    {
        $script = 'define("BASE_PATH", ' . var_export(BASE_PATH, true) . '); require BASE_PATH . "/vendor/autoload.php"; echo \App\Common\Security\IntegrityKey::isConfigured() ? "configured" : "missing";';
        $run = function (string $environment) use ($script): string {
            $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['APP_ENV' => $environment, IntegrityKey::TEST_OVERRIDE_VARIABLE => IntegrityKey::TEST_NO_KEY]));
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return (string) $output;
        };

        // Under testing the empty override wins; under production it is ignored and the configured key counts
        $this->assertSame('missing', $run('testing'));
        $this->assertSame('configured', $run('production'));
    }
}
