<?php

namespace Tests\Unit;

use App\Common\Config\Config;
use App\Common\Middleware\CacheMiddleware;
use App\Modules\Integrity\Jobs\ChainCheckpointJob;
use App\Modules\Integrity\Jobs\IntegrityCheckJob;
use PHPUnit\Framework\TestCase;

/**
 * Every file a test creates lives under working-php/.test/, never where the real app keeps its data.
 */
class TestArtifactPathsTest extends TestCase
{
    // The folder all test files go under
    private const TEST_ROOT = BASE_PATH . '/.test/';

    // The helper resolves a relative path inside the .test folder
    public function test_test_path_resolves_inside_dot_test(): void
    {
        $this->assertSame(self::TEST_ROOT . 'backups', Config::testPath('backups'));
    }

    // Under APP_ENV=testing, checkpoints go to the .test folder
    public function test_checkpoints_use_test_directory_when_testing(): void
    {
        $this->assertSame(self::TEST_ROOT . 'checkpoints', ChainCheckpointJob::directory());
    }

    // Under APP_ENV=testing, the audit result cache goes to the .test folder
    public function test_integrity_result_uses_test_file_when_testing(): void
    {
        $this->assertSame(self::TEST_ROOT . 'cache/integrity-check-result.json', IntegrityCheckJob::resultPath());
    }

    // Under APP_ENV=testing, the response cache (and every cache-clearing path) uses the .test folder
    public function test_response_cache_uses_test_directory_when_testing(): void
    {
        $this->assertSame(self::TEST_ROOT . 'cache/responses', CacheMiddleware::cacheDir());
    }

    // Files allowed to name the system temp directory: this test, and the seeder test that places sentinel
    // files in the real cache and session folders to prove the seeder leaves them alone under testing
    private const TEMP_DIR_EXCEPTIONS = ['tests/Unit/TestArtifactPathsTest.php', 'tests/Workflow/SeederDuplicateRetryTest.php'];

    // No test writes to the system temp directory; Tests\Support\TestFiles gives paths under .test/tmp
    public function test_no_test_uses_the_system_temp_directory(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/tests', \FilesystemIterator::SKIP_DOTS));

        // Scan every test source file outside the two documented exceptions
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen(BASE_PATH) + 1);
            if ($file->getExtension() !== 'php' || in_array($relative, self::TEMP_DIR_EXCEPTIONS, true)) {
                continue;
            }
            if (preg_match('/\b(sys_get_temp_dir|tempnam)\s*\(/', file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }
}
