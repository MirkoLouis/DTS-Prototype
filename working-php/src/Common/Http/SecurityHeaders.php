<?php

namespace App\Common\Http;

/**
 * The response headers every PHP response carries, sent from ONE place (public/index.php, before routing).
 *
 * Today that is the Content Security Policy in report-only mode: a browser applies it to the page, reports what
 * would be blocked (securitypolicyviolation event, console message) and blocks nothing. Enforcing it later only
 * means switching HEADER_NAME to 'Content-Security-Policy'; the policy itself stays in POLICY.
 */
class SecurityHeaders
{
    // Report-only today; the name of the enforcing header is 'Content-Security-Policy'
    public const HEADER_NAME = 'Content-Security-Policy-Report-Only';

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

    /**
     * Send the headers for the current response (before any output; safe to call once per request).
     */
    public static function send(): void
    {
        // Nothing to send once the output has started (for example a CLI run or a test that already printed)
        if (headers_sent()) {
            return;
        }

        header(self::HEADER_NAME . ': ' . self::POLICY);
    }
}
