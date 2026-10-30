<?php

namespace Tests\Unit;

use App\Jobs\ChainCheckpointJob;
use App\Jobs\IntegrityCheckJob;
use App\Common\Middleware\CacheMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestLifecycle;

/**
 * The per-run database and -test artifact lifecycle.
 * Uses a throwaway database so the shared suite database is never dropped.
 */
class TestLifecycleTest extends TestCase
{
    private const THROWAWAY_DB = 'deped_dts_lifecycle_test';

    private array $config;

    protected function setUp(): void
    {
        // Same server and credentials as the suite, but a separate throwaway database name
        $config = require BASE_PATH . '/src/Common/Config/config.php';
        $this->config = $config['database'];
        $this->config['dbname'] = self::THROWAWAY_DB;
    }

    protected function tearDown(): void
    {
        // Never leave the throwaway database behind, even if an assertion failed
        $this->serverPdo()->exec('DROP DATABASE IF EXISTS `' . self::THROWAWAY_DB . '`');
    }

    // Server-level connection with no database selected
    private function serverPdo(): PDO
    {
        $c = $this->config;
        return new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    // True when the throwaway database exists on the server
    private function dbExists(): bool
    {
        return (bool) $this->serverPdo()->query("SHOW DATABASES LIKE '" . self::THROWAWAY_DB . "'")->fetch();
    }

    // The three -test artifact paths, taken from the app's own path helpers
    private function artifactPaths(): array
    {
        return [CacheMiddleware::cacheDir(), ChainCheckpointJob::directory(), IntegrityCheckJob::resultPath()];
    }

    // A database name that does not end in _test is refused before anything is dropped
    public function test_lifecycle_refuses_non_test_database(): void
    {
        $config = $this->config;
        $config['dbname'] = 'deped_dts';

        $this->expectException(RuntimeException::class);
        TestLifecycle::begin($config);
    }

    // end() refuses a non-test database too
    public function test_end_refuses_non_test_database(): void
    {
        $config = $this->config;
        $config['dbname'] = 'deped_dts';

        $this->expectException(RuntimeException::class);
        TestLifecycle::end($config);
    }

    // begin() leaves a database with no tables, even if one with tables existed before
    public function test_begin_creates_empty_database(): void
    {
        // Pre-create the database with a table to prove it is dropped
        $pdo = $this->serverPdo();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . self::THROWAWAY_DB . '`');
        $pdo->exec('CREATE TABLE `' . self::THROWAWAY_DB . '`.leftover (id INT)');

        TestLifecycle::begin($this->config);

        $this->assertTrue($this->dbExists());
        $tables = $pdo->query('SHOW TABLES FROM `' . self::THROWAWAY_DB . '`')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([], $tables);
    }

    // end() drops the database and removes the three -test artifacts
    public function test_end_drops_database_and_test_artifacts(): void
    {
        TestLifecycle::begin($this->config);

        // Put something in each artifact location
        [$cache, $checkpoints, $result] = $this->artifactPaths();
        file_put_contents($cache . '/page.html', 'x');
        file_put_contents($checkpoints . '/checkpoint-x.json', '{}');
        file_put_contents($result, '{}');

        TestLifecycle::end($this->config);

        $this->assertFalse($this->dbExists());
        foreach ($this->artifactPaths() as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    // begin() recreates the artifact directories empty
    public function test_begin_recreates_empty_artifact_directories(): void
    {
        [$cache, $checkpoints] = $this->artifactPaths();
        @mkdir($cache, 0755, true);
        file_put_contents($cache . '/stale.html', 'x');

        TestLifecycle::begin($this->config);

        $this->assertDirectoryExists($cache);
        $this->assertDirectoryExists($checkpoints);
        $this->assertSame([], glob($cache . '/*'));
        $this->assertSame([], glob($checkpoints . '/*'));
    }

    // Result CSVs are evidence and must survive
    public function test_lifecycle_never_touches_result_csvs(): void
    {
        $dir = BASE_PATH . '/storage/test-results';
        @mkdir($dir, 0755, true);
        $sentinel = $dir . '/lifecycle-sentinel.csv';
        file_put_contents($sentinel, "a,b\n");

        try {
            TestLifecycle::begin($this->config);
            TestLifecycle::end($this->config);
            $this->assertFileExists($sentinel);
        } finally {
            @unlink($sentinel);
        }
    }
}
