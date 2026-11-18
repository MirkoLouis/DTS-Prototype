<?php

namespace Tests\Support;

/**
 * The security headers every PHP response must carry (S9b), as one assertion shared by the tests that look at
 * responses. The expected values are written out here on purpose: changing the policy must be a deliberate edit.
 *
 * Needs the response array of HttpSession (with 'all_headers').
 */
trait AssertsSecurityHeaders
{
    // The policy exactly as agreed for this version
    private const EXPECTED_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    // The values every response carries, by lower-cased header name
    private const EXPECTED_HEADERS = [
        'content-security-policy' => self::EXPECTED_POLICY,
        'x-frame-options' => 'DENY',
        'x-content-type-options' => 'nosniff',
        'referrer-policy' => 'same-origin',
    ];

    // Headers a response must never carry: report-only mode is over, HSTS is deliberately not sent (the site runs on a
    // self-signed LAN certificate: browsers ignore HSTS there, and one that honoured it would remove the certificate-warning
    // bypass the deployment depends on), and the PHP version is not announced
    private const ABSENT_HEADERS = ['content-security-policy-report-only', 'strict-transport-security', 'x-powered-by'];

    // The response carries every header once with the agreed value, and none of the forbidden ones
    private function assertSecurityHeaders(array $response, string $what): void
    {
        foreach (self::EXPECTED_HEADERS as $name => $value) {
            $this->assertSame([$value], $response['all_headers'][$name] ?? [], "{$what}: {$name} exactly once with the agreed value");
        }
        foreach (self::ABSENT_HEADERS as $name) {
            $this->assertArrayNotHasKey($name, $response['all_headers'], "{$what}: {$name} must not be sent");
        }
    }
}
