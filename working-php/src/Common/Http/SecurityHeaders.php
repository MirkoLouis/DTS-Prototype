<?php

namespace App\Common\Http;

/**
 * The security response headers, defined in ONE place: this class is the single source of truth for their names,
 * values and the NGINX directives that repeat them.
 *
 * - PHP sends them with send() on every PHP response (public/index.php, before routing): pages, JSON, redirects,
 *   error pages and downloads. This is what the built-in server (tests, `php -S`) serves.
 * - Behind NGINX the same headers are added by NGINX itself (nginx/site.conf, filled in by scripts/server-lib.php
 *   from nginxAddHeaderDirectives()), because NGINX also answers requests PHP never sees: static files, the
 *   gateway error page and its own error responses. For responses that come from PHP-FPM the site config drops
 *   PHP's copy (fastcgi_hide_header, nginxHiddenHeaderDirectives()), so a response carries each header exactly once.
 *
 * Strict-Transport-Security is deliberately NOT sent: the site runs on a self-signed LAN certificate, browsers ignore
 * the header on an untrusted certificate, and a browser that did honour it would remove the certificate-warning
 * bypass this deployment depends on.
 */
class SecurityHeaders
{
    // The enforcing header (a browser blocks what the policy does not allow)
    public const HEADER_NAME = 'Content-Security-Policy';

    // The policy: every script and resource comes from this origin, nothing is embedded or framed
    public const POLICY = "default-src 'self'; "
        // Scripts only from files of this origin (no inline scripts, no eval)
        . "script-src 'self'; "
        // Stylesheets from this origin; inline style attributes and <style> blocks are still used by the views
        . "style-src 'self' 'unsafe-inline'; "
        // Images from this origin, data: URIs (QR codes, library icons) and blob: URLs (scanner image preview)
        . "img-src 'self' data: blob:; "
        // Camera video fallback of the QR library creates blob: URLs
        . "media-src 'self' blob:; "
        // Fonts from this origin (public/fonts)
        . "font-src 'self' data:; "
        // fetch() and polling only to this origin
        . "connect-src 'self'; "
        // No plugins
        . "object-src 'none'; "
        // The app cannot be framed (clickjacking)
        . "frame-ancestors 'none'; "
        // A <base> tag cannot redirect relative URLs
        . "base-uri 'self'; "
        // Forms post to this origin only
        . "form-action 'self'";

    // Every header sent with a response: name => value
    public const HEADERS = [
        // What a page may load and run
        self::HEADER_NAME => self::POLICY,
        // Older browsers: the page cannot be framed (frame-ancestors is the modern form of the same rule)
        'X-Frame-Options' => 'DENY',
        // The browser must not guess a content type (a download or JSON is never run as a page or script)
        'X-Content-Type-Options' => 'nosniff',
        // The Referer is sent to this origin only, never to another site
        'Referrer-Policy' => 'same-origin',
    ];

    // Headers that tell visitors which software runs the server (removed from every response)
    public const REMOVED_HEADERS = ['X-Powered-By'];

    /**
     * Send the headers for the current response (before any output; safe to call once per request).
     */
    public static function send(): void
    {
        // Nothing to send once the output has started (for example a CLI run or a test that already printed)
        if (headers_sent()) {
            return;
        }

        // PHP adds X-Powered-By itself (expose_php); ini_set('expose_php') has no effect at runtime, header_remove() does
        foreach (self::REMOVED_HEADERS as $name) {
            header_remove($name);
        }

        // Replace, never append: a second call cannot duplicate a header
        foreach (self::HEADERS as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * The NGINX `add_header` lines for every header, one per line (indentation is the template's).
     *
     * `always` adds them to error responses too. A value never contains a double quote, backslash or `$`, which
     * NGINX would treat specially inside a double-quoted string.
     */
    public static function nginxAddHeaderDirectives(string $indent = '    '): string
    {
        $lines = [];
        foreach (self::HEADERS as $name => $value) {
            $lines[] = self::nginxDirectiveFor($name, $value, $indent);
        }

        return implode("\n", $lines);
    }

    /**
     * One `add_header ... always;` line. A value with a double quote, backslash, `$`, newline or carriage return is
     * refused: it is a mistake in the constants (NGINX treats these specially, a line break would end the directive and
     * let the rest of the value become another one), not something to escape.
     */
    public static function nginxDirectiveFor(string $name, string $value, string $indent = ''): string
    {
        if (preg_match('/["\\\\$\r\n]/', $value) === 1) {
            throw new \LogicException("Header value of {$name} contains a character that is special in an NGINX string.");
        }

        return $indent . 'add_header ' . $name . ' "' . $value . '" always;';
    }

    /**
     * The NGINX `fastcgi_hide_header` lines that drop PHP's own copy of every header (and X-Powered-By).
     */
    public static function nginxHiddenHeaderDirectives(string $indent = '        '): string
    {
        $lines = [];
        foreach ([...array_keys(self::HEADERS), ...self::REMOVED_HEADERS] as $name) {
            $lines[] = $indent . 'fastcgi_hide_header ' . $name . ';';
        }

        return implode("\n", $lines);
    }
}
