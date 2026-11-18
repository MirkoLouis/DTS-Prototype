<?php

namespace App\Common\Security;

use App\Common\Config\Config;

/**
 * The one place that reads and decodes APP_INTEGRITY_KEY (base64 of exactly 32 random bytes).
 *
 * The key is the HMAC-SHA256 secret of the system audit log and of the chain seal. Every user of the
 * key goes through this class, so the decoding rules exist once.
 */
class IntegrityKey
{
    // Length in bytes of a valid key
    public const KEY_BYTES = 32;

    // Test seam: under APP_ENV=testing this environment variable replaces the configured key.
    // TEST_NO_KEY (or any other invalid value) means "no key"; an empty value is not used because
    // a child process started with an empty environment variable does not receive it at all.
    public const TEST_OVERRIDE_VARIABLE = 'DTS_TEST_INTEGRITY_KEY';
    public const TEST_NO_KEY = 'none';

    /**
     * The raw key, or null when none (or an invalid one) is configured.
     */
    public static function raw(): ?string
    {
        // Read the configured value, or the test override when the test suite is running
        $encoded = self::encodedValue();

        // Strict base64 that decodes to exactly 32 bytes; anything else counts as "not configured"
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
            return null;
        }

        return $decoded;
    }

    /**
     * Whether a valid key is configured.
     */
    public static function isConfigured(): bool
    {
        return self::raw() !== null;
    }

    // What guests and staff see when the key is missing (no operator text, no file names)
    public const USER_MESSAGE = 'The system cannot record this action right now. Please contact the administrator.';

    /**
     * The raw key, or an IntegrityKeyMissingException with the generic user message (the operator text is logged).
     */
    public static function require(): string
    {
        $key = self::raw();
        if ($key === null) {
            // The operator detail goes to the error log; guests and staff only see the generic message
            error_log(self::missingMessage());
            throw new IntegrityKeyMissingException(self::USER_MESSAGE);
        }

        return $key;
    }

    /**
     * HMAC-SHA256 of a payload under the key, as lowercase hex (requires a configured key).
     */
    public static function hmac(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::require());
    }

    /**
     * The operator-facing message for a missing or invalid key.
     */
    public static function missingMessage(): string
    {
        return 'APP_INTEGRITY_KEY is missing or invalid in .env (it must be base64 of 32 random bytes). '
            . 'Run "php scripts/generate-keys.php --integrity" on a new installation, or restore the key from your backup copy of .env. '
            . 'Without it the chain seal cannot be created or verified.';
    }

    /**
     * The configured key text, honouring the test override only while the test suite runs.
     */
    private static function encodedValue(): string
    {
        // The override is ignored outside the test environment
        if (Config::isTesting()) {
            $override = getenv(self::TEST_OVERRIDE_VARIABLE);
            if ($override !== false) {
                return $override;
            }
        }

        return (string) Config::get('app', 'integrity_key', '');
    }
}
