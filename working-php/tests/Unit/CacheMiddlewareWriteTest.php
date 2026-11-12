<?php

namespace Tests\Unit;

use App\Common\Middleware\CacheMiddleware;
use App\Research\Barrier;
use Tests\Support\HttpClient;
use Tests\TestCase;

class CacheMiddlewareWriteTest extends TestCase
{
    private string $dir;

    // Use a scratch directory for direct writeAtomic calls
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/dts-cache-write-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    // Remove the scratch directory and reset the write seam
    protected function tearDown(): void
    {
        CacheMiddleware::$writer = null;
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @chmod($file, 0600);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    // A second write replaces the first completely
    public function test_write_atomic_replaces_content(): void
    {
        $file = $this->dir . '/page.html';
        CacheMiddleware::writeAtomic($file, str_repeat('a', 5000));
        CacheMiddleware::writeAtomic($file, 'short');

        $this->assertSame('short', file_get_contents($file));
    }

    // Interleaved writers never leave a temp file behind and the final file is always whole
    public function test_write_atomic_leaves_no_temp_files(): void
    {
        $file = $this->dir . '/page.html';
        $dir = $this->dir;
        Barrier::forkAll(5, function (int $index) use ($file): array {
            // Each worker writes ten times with its own distinct, large body
            for ($i = 0; $i < 10; $i++) {
                CacheMiddleware::writeAtomic($file, str_repeat((string) $index, 20000));
            }

            return ['ok' => true];
        });

        $this->assertSame([], glob($dir . '/*.tmp'));
        $content = file_get_contents($file);
        $this->assertSame(20000, strlen($content));
        $this->assertSame(1, count(array_unique(str_split($content))));
    }

    // A zero-byte cache file must be treated as a miss so the page is rendered fresh
    public function test_empty_cache_file_is_treated_as_miss(): void
    {
        $client = HttpClient::start();
        $dir = CacheMiddleware::cacheDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $empty = $dir . '/cache_guest_' . md5('/') . '.html';
        touch($empty);

        $body = $client->get('/')['body'];
        $this->assertMatchesRegularExpression('/name="csrf-token"\s+content="[^"]+"/i', $body);
        @unlink($empty);
    }

    // Empty output is never written
    public function test_empty_output_is_not_cached(): void
    {
        $file = $this->dir . '/page.html';
        CacheMiddleware::writeAtomic($file, '');

        $this->assertFileDoesNotExist($file);
        $this->assertSame([], glob($this->dir . '/*'));
    }

    // A short write (fewer bytes than the page) is discarded: the temp file is removed and the old cache file is kept
    public function test_short_write_is_not_renamed(): void
    {
        $file = $this->dir . '/page.html';
        file_put_contents($file, 'previous complete page');

        // Seam: simulate a disk-full write that stores only the first 10 bytes
        CacheMiddleware::$writer = static function (string $path, string $data): int {
            return (int) file_put_contents($path, substr($data, 0, 10));
        };
        CacheMiddleware::writeAtomic($file, str_repeat('a', 5000));

        $this->assertSame('previous complete page', file_get_contents($file));
        $this->assertSame([], glob($this->dir . '/*.tmp'));
    }

    // A short write with no previous cache file leaves no file at all
    public function test_short_write_creates_no_file(): void
    {
        $file = $this->dir . '/page.html';
        CacheMiddleware::$writer = static function (string $path, string $data): int {
            return (int) file_put_contents($path, substr($data, 0, 1));
        };
        CacheMiddleware::writeAtomic($file, 'complete page');

        $this->assertSame([], glob($this->dir . '/*'));
    }

    // A failed write in an unwritable directory leaves nothing behind
    public function test_write_into_unwritable_directory_leaves_nothing(): void
    {
        // Root ignores directory permissions, so the check is meaningless there
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root.');
        }
        chmod($this->dir, 0500);
        try {
            CacheMiddleware::writeAtomic($this->dir . '/page.html', 'complete page');
        } finally {
            chmod($this->dir, 0700);
        }

        $this->assertSame([], glob($this->dir . '/*'));
    }

    // readCached returns null for missing, empty and unreadable files, and the content otherwise
    public function test_read_cached_rejects_unusable_files(): void
    {
        $this->assertNull(CacheMiddleware::readCached($this->dir . '/missing.html'));

        $empty = $this->dir . '/empty.html';
        touch($empty);
        $this->assertNull(CacheMiddleware::readCached($empty));

        $good = $this->dir . '/good.html';
        file_put_contents($good, '<html>ok</html>');
        $this->assertSame('<html>ok</html>', CacheMiddleware::readCached($good));

        // An unreadable file makes file_get_contents return false (skipped as root, which can read anything)
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            chmod($good, 0000);
            $this->assertNull(CacheMiddleware::readCached($good));
        }
    }

    // A fresh, non-empty but unreadable cache file falls through to a normal render instead of a blank page
    public function test_unreadable_cache_file_falls_through_to_render(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root.');
        }
        $client = HttpClient::start();
        $dir = CacheMiddleware::cacheDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $cached = $dir . '/cache_guest_' . md5('/') . '.html';
        file_put_contents($cached, 'stale cached page');
        chmod($cached, 0000);

        try {
            $body = $client->get('/')['body'];
        } finally {
            @chmod($cached, 0600);
            @unlink($cached);
        }
        $this->assertMatchesRegularExpression('/name="csrf-token"\s+content="[^"]+"/i', $body);
    }
}
