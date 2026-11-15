<?php

namespace App\Common\Http;

/**
 * Redirect targets that come from a request header (the Referer) or are passed on by a script.
 *
 * A target is used only when it is a path on this site; it is reduced to path and query, so it can never
 * name another host. This is the single place for those rules (notification links share isRelativePath()).
 */
class SafeRedirect
{
    /**
     * Whether a string is a plain relative path: one leading "/", no "//host", no backslash,
     * no whitespace or control character (browsers strip tabs and newlines inside URLs).
     */
    public static function isRelativePath(string $path): bool
    {
        // Must start with "/" and not be protocol-relative ("//host")
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        // No backslash, whitespace or control characters anywhere
        return preg_match('/[\x00-\x20\x7f\\\\]/', $path) !== 1;
    }

    /**
     * Reduce a target to a local path plus query, or null when it is not local.
     *
     * Accepted: a relative path, or an absolute http(s) URL whose host and port equal the current request's
     * (without user information). The fragment is dropped.
     *
     * @param string|null $currentHost "host[:port]" of this site; defaults to the request's Host header
     */
    public static function localPath(?string $target, ?string $currentHost = null): ?string
    {
        // Nothing to follow; control characters, whitespace and backslashes are never valid
        if ($target === null || $target === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $target) === 1) {
            return null;
        }

        // A relative path is local as it is (minus its fragment)
        if (str_starts_with($target, '/')) {
            return self::withoutFragment($target);
        }

        // Otherwise only an absolute http(s) URL of this very host can be local
        $parts = parse_url($target);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $currentHost = strtolower($currentHost ?? ($_SERVER['HTTP_HOST'] ?? ''));
        $targetHost = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($currentHost === '' || $targetHost !== $currentHost) {
            return null;
        }

        // Path and query only, and the result must itself be a plain path ("https://host//evil" is not)
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $local = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return self::isRelativePath($local) ? $local : null;
    }

    /**
     * The request's Referer as a local path, or the fallback when it is missing, foreign or malformed.
     */
    public static function refererOr(string $fallback): string
    {
        return self::localPath($_SERVER['HTTP_REFERER'] ?? null) ?? $fallback;
    }

    // A relative path without its #fragment, or null when it is not a plain relative path
    private static function withoutFragment(string $path): ?string
    {
        $withoutFragment = explode('#', $path, 2)[0];

        return self::isRelativePath($withoutFragment) ? $withoutFragment : null;
    }
}
