<?php

namespace App\Common\Middleware;

class CacheMiddleware
{
    /**
     * Test seam: replaces file_put_contents in writeAtomic (signature: fn(string $path, string $data): int|false).
     * Null in production.
     */
    public static ?\Closure $writer = null;

    /**
     * Directory of cached responses (no trailing slash); tests use a separate -test directory.
     */
    public static function cacheDir(): string
    {
        return BASE_PATH . (\App\Common\Config\Config::get('app', 'env') === 'testing' ? '/cache/responses-test' : '/cache/responses');
    }

    /**
     * Write a cache file atomically: temp file in the same directory, then rename() over the target,
     * so a concurrent reader sees either the old complete file or the new complete one. Empty output is never cached.
     */
    public static function writeAtomic(string $file, string $html): void
    {
        // An empty page would be served as a blank page with no CSRF token
        if ($html === '') {
            return;
        }

        // Same directory keeps rename() on one filesystem (atomic)
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = self::$writer !== null ? (self::$writer)($temp, $html) : @file_put_contents($temp, $html);

        // A failed or short write (e.g. disk full) must never replace the cache file
        if ($written !== strlen($html)) {
            @unlink($temp);
            return;
        }

        // Failed rename: leave nothing behind
        if (!@rename($temp, $file)) {
            @unlink($temp);
        }
    }

    /**
     * Read a cache file; null when it cannot be read or is empty, so the caller renders the page normally.
     */
    public static function readCached(string $file): ?string
    {
        $content = @file_get_contents($file);

        // false (unreadable/vanished) and '' (truncated) are both a miss, never a blank page
        if ($content === false || $content === '') {
            return null;
        }

        return $content;
    }

    public function handle($ttl = 55)
    {
        // Only cache GET requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }

        // Do not serve or create cache if there are pending flash messages
        if (isset($_SESSION['success']) || isset($_SESSION['error']) || isset($_SESSION['info'])
            || isset($_SESSION[\App\Security\SecurityPinCache::SESSION_ISSUED]) || isset($_SESSION[\App\Security\SecurityPinCache::SESSION_CLEAR])) {
            return;
        }

        $ttl = (int)$ttl;
        // Include query parameters in URI
        $requestUri = $_SERVER['REQUEST_URI'];
        
        $userId = $_SESSION['user_id'] ?? 'guest';
        $prefix = "cache_" . ($userId === 'guest' ? 'guest' : "user_{$userId}");
        
        // Use md5 of URI
        $uriHash = md5($requestUri);
        // Tests use a separate cache directory so they never read pages cached by real use
        $cacheDir = self::cacheDir();
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/' . $prefix . '_' . $uriHash . '.html';

        // A fresh cache file that reads back as usable content is served; otherwise fall through to a normal render
        $cachedOutput = null;
        if (file_exists($cacheFile) && filesize($cacheFile) > 0 && (time() - filemtime($cacheFile)) < $ttl) {
            $cachedOutput = self::readCached($cacheFile);
        }

        if ($cachedOutput !== null) {
            // Serve cache and exit

            // DYNAMIC CSRF INJECTION:
            // Since we cache full HTML pages, the cached HTML contains the CSRF token of the user 
            // who originally triggered the cache. We MUST replace it with the current user's token 
            // before serving, otherwise their POST requests will fail with a 419 Page Expired.
            if (!empty($_SESSION['csrf_token'])) {
                $cachedOutput = preg_replace(
                    '/(<input\s+type="hidden"\s+name="csrf_token"\s+value=")[^"]*("\s*\/?>)/i',
                    '${1}' . $_SESSION['csrf_token'] . '${2}',
                    $cachedOutput
                );
                
                $cachedOutput = preg_replace(
                    '/(<meta\s+name="csrf-token"\s+content=")[^"]*("\s*\/?>)/i',
                    '${1}' . $_SESSION['csrf_token'] . '${2}',
                    $cachedOutput
                );
            }

            echo $cachedOutput;
            echo "\n<!-- Served from Response Cache (TTL: {$ttl}s) -->";
            exit;
        }

        // Start output buffering
        ob_start();

        // Register shutdown function to capture and save output
        register_shutdown_function(function () use ($cacheFile, $cacheDir) {
            $output = ob_get_clean();
            
            // Check headers to avoid caching redirects
            $headers = headers_list();
            foreach ($headers as $header) {
                if (stripos($header, 'Location:') === 0) {
                    echo $output;
                    return;
                }
            }

            // Only cache valid HTTP 200 responses
            $code = http_response_code();
            if ($code === false || $code === 200) {
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }
                self::writeAtomic($cacheFile, $output);
            }

            echo $output;
        });
    }
}
