<?php

namespace Tests\Http;

use App\Common\Middleware\CacheMiddleware;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * The response cache stores bare URLs only, so query strings cannot be used to create cache files without limit.
 */
class CacheQueryStringTest extends TestCase
{
    // Number of cached pages on disk
    private function cacheFileCount(): int
    {
        return count(glob(CacheMiddleware::cacheDir() . '/*.html') ?: []);
    }

    // Requests that differ only in their query string create no cache files
    public function test_query_string_urls_create_no_cache_files(): void
    {
        $client = HttpClient::start();
        $before = $this->cacheFileCount();

        // Three distinct query strings on the cached guest page
        foreach (['/?x=1', '/?x=2', '/?x=3'] as $url) {
            $this->assertSame(200, $client->get($url)['status']);
        }

        $this->assertSame($before, $this->cacheFileCount());
    }

    // The bare URL is still cached: two requests leave exactly one cache file
    public function test_bare_url_is_still_cached(): void
    {
        $client = HttpClient::start();
        $before = $this->cacheFileCount();
        $client->get('/');
        $second = $client->get('/');

        $this->assertSame(200, $second['status']);
        $this->assertSame($before + 1, $this->cacheFileCount());
    }
}
