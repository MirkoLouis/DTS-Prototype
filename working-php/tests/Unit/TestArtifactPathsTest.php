<?php

namespace Tests\Unit;

use App\Jobs\ChainCheckpointJob;
use App\Jobs\IntegrityCheckJob;
use App\Middleware\CacheMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Files written by tests must never land where the real app reads its latest results.
 */
class TestArtifactPathsTest extends TestCase
{
    // Under APP_ENV=testing, checkpoints go to a separate -test directory
    public function test_checkpoints_use_test_directory_when_testing(): void
    {
        $this->assertSame(BASE_PATH . '/storage/checkpoints-test', ChainCheckpointJob::directory());
    }

    // Under APP_ENV=testing, the audit result cache goes to a separate -test file
    public function test_integrity_result_uses_test_file_when_testing(): void
    {
        $this->assertSame(BASE_PATH . '/cache/integrity-check-result-test.json', IntegrityCheckJob::resultPath());
    }

    // Under APP_ENV=testing, the response cache (and every cache-clearing path) uses a separate -test directory
    public function test_response_cache_uses_test_directory_when_testing(): void
    {
        $this->assertSame(BASE_PATH . '/cache/responses-test', CacheMiddleware::cacheDir());
    }
}
