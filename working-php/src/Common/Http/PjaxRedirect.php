<?php

namespace App\Common\Http;

/**
 * Keeps redirects out of in-app navigation.
 *
 * pjax-router.js loads pages with fetch() and "X-PJAX: true". fetch() follows a redirect, so the page the
 * redirect leads to would be rendered once inside fetch() (consuming its flash message) and then loaded again
 * by the browser without it. For such requests the Location header is therefore replaced, just before the
 * headers are sent, by status 204 and an X-PJAX-Location header; the script then loads that page itself.
 */
class PjaxRedirect
{
    // Response header carrying the redirect target
    public const HEADER = 'X-PJAX-Location';

    /**
     * Install the rewrite for the current request (a no-op for requests that are not PJAX requests).
     */
    public static function register(): void
    {
        // Only requests from the navigation script are rewritten
        if (!request_is_pjax()) {
            return;
        }

        // The callback runs when PHP flushes the response at the end of the request, where autoloading no longer
        // works, so every class it uses is loaded now
        class_exists(SafeRedirect::class);

        header_register_callback([self::class, 'rewriteRedirect']);
    }

    /**
     * Run by PHP just before the response headers are sent: turn a redirect into a 204 with the target in a header.
     *
     * Public only because header_register_callback() calls it.
     */
    public static function rewriteRedirect(): void
    {
        // Find the redirect target among the headers about to be sent
        $target = null;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0) {
                $target = trim(substr($header, strlen('Location:')));
            }
        }

        // Not a redirect
        if ($target === null) {
            return;
        }

        // An off-site or malformed target is left as the normal redirect; the script never follows it
        $localPath = SafeRedirect::localPath($target);
        if ($localPath === null) {
            return;
        }

        // 204 is a success status without a body, so fetch() hands the response to the script instead of following it;
        // the answer depends on the X-PJAX request header, so caches must not share it with plain requests
        header_remove('Location');
        http_response_code(204);
        header(self::HEADER . ': ' . $localPath);
        header('Vary: X-PJAX', false);
    }
}
