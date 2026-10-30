<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Integrity\DigitalSignature;
use App\Core\TrackingCode;
use App\Jobs\IntegrityCheckJob;
use Tests\TestCase;

/**
 * R13: tracking-code uniqueness in the seeders is enforced by the UNIQUE index.
 * A 1062 on the documents INSERT makes the seeder rebuild the chunk with fresh codes and retry.
 */
class SeederDuplicateRetryTest extends TestCase
{
    // The audit memoizes the seeder's keys; later tests reuse the baseline keys, so leave the cache empty
    protected function tearDown(): void
    {
        $this->resetKeyCache();
        parent::tearDown();
    }

    // A forced duplicate in the first chunk must be recovered, leaving 20 distinct, fully verifiable documents
    public function test_fast_seed_recovers_from_forced_duplicate(): void
    {
        // Run the real seeder against the test database with the duplicate seam switched on
        $cmd = 'APP_ENV=testing DTS_TEST_FORCE_DUPLICATE=1 ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(BASE_PATH . '/scripts/seeders/fast-seed.php') . ' 20 2>&1';
        exec($cmd, $out, $exit);
        $output = implode("\n", $out);
        $this->assertSame(0, $exit, $output);

        // The seam must actually have caused a 1062 retry, otherwise this test proves nothing
        $this->assertStringContainsString('Duplicate tracking code', $output);

        // Exactly 20 documents with 20 distinct, well-formed codes
        $codes = Database::getInstance()->query('SELECT tracking_code FROM documents')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(20, $codes);
        $this->assertCount(20, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(TrackingCode::PATTERN, $code);
        }

        // Every rebuilt chain must verify: hashes, signatures and live state
        $results = $this->runAudit();
        $this->assertGreaterThan(0, (int) $results['total_logs']);
        $this->assertEquals(100, $results['verified_percentage'], json_encode($results));
        $this->assertSame(0, (int) $results['live_state_errors_count']);
        $this->assertSame(0, (int) $results['invalid_signatures']);
    }

    // Outside app.env=testing neither the env var nor an explicitly set override may change generate()
    public function test_override_ignored_outside_testing(): void
    {
        // Control: under testing the seam must make the first two codes identical
        $testing = $this->probe('testing');
        $this->assertSame($testing['first'], $testing['second'], 'Seam did not fire under testing; the negative check below would be meaningless');
        $this->assertSame('DEPED-0000000000', $testing['explicit']);

        // Under production the same env var and the same explicit override are ignored
        $production = $this->probe('production');
        $this->assertNotSame($production['first'], $production['second']);
        $this->assertNotSame('DEPED-0000000000', $production['explicit']);
        $this->assertMatchesRegularExpression(TrackingCode::PATTERN, $production['explicit']);
    }

    // R20: a seed under testing must not delete real session files or the operational response cache
    public function test_fast_seed_under_testing_keeps_sessions_and_real_cache(): void
    {
        // Plant one sentinel in the real response cache and one in the session save path
        $uniq = bin2hex(random_bytes(6));
        $cacheDir = BASE_PATH . '/cache/responses';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheSentinel = $cacheDir . '/sentinel-' . $uniq . '.html';
        $sessionSentinel = (session_save_path() ?: sys_get_temp_dir()) . '/sess_sentinel' . $uniq;
        file_put_contents($cacheSentinel, 'sentinel');
        file_put_contents($sessionSentinel, 'sentinel');

        try {
            // Seed 5 documents against the test database
            $cmd = 'APP_ENV=testing ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(BASE_PATH . '/scripts/seeders/fast-seed.php') . ' 5 2>&1';
            exec($cmd, $out, $exit);
            $this->assertSame(0, $exit, implode("\n", $out));

            $this->assertFileExists($cacheSentinel);
            $this->assertFileExists($sessionSentinel);
        } finally {
            // Always remove both sentinels
            @unlink($cacheSentinel);
            @unlink($sessionSentinel);
        }
    }

    // The in-memory seen-set helper must be gone from the app and the scripts
    public function test_no_seen_set_remains(): void
    {
        // Scan every PHP file under scripts/ and src/
        $hits = [];
        foreach (['scripts', 'src'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                // Only PHP sources are relevant
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if (str_contains($source, 'generateUnique') || str_contains($source, 'seenTrackingCodes')) {
                    $hits[] = substr($file->getPathname(), strlen(BASE_PATH) + 1);
                }
            }
        }

        $this->assertSame([], $hits);
        $this->assertFalse(method_exists(TrackingCode::class, 'generateUnique'));
    }

    // Run generate() in a fresh process whose Config reports $env, with DTS_TEST_FORCE_DUPLICATE=1 set
    private function probe(string $env): array
    {
        // Replace the memoized config with one whose app.env is $env, then exercise both override paths
        $code = 'define("BASE_PATH", ' . var_export(BASE_PATH, true) . ');'
            . 'require BASE_PATH . "/vendor/autoload.php";'
            . '$cfg = require BASE_PATH . "/src/Common/Config/config.php";'
            . '$cfg["app"]["env"] = ' . var_export($env, true) . ';'
            . '$p = new ReflectionProperty(App\Common\Config\Config::class, "config"); $p->setValue(null, $cfg);'
            . '$first = App\Core\TrackingCode::generate(); $second = App\Core\TrackingCode::generate();'
            . 'App\Core\TrackingCode::setTestOverride(fn() => "DEPED-0000000000");'
            . '$explicit = App\Core\TrackingCode::generate();'
            . 'echo json_encode(compact("first", "second", "explicit"));';
        $cmd = 'APP_ENV=testing DTS_TEST_FORCE_DUPLICATE=1 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1';
        exec($cmd, $out, $exit);
        $this->assertSame(0, $exit, implode("\n", $out));

        $result = json_decode(implode('', $out), true);
        $this->assertIsArray($result, implode("\n", $out));
        return $result;
    }

    // Empty DigitalSignature's private static key-history cache
    private function resetKeyCache(): void
    {
        DigitalSignature::clearKeyCache();
    }

    // Queue and run one audit exactly as the System Health page does, and return its results
    private function runAudit(): array
    {
        // The seeder subprocess issued new user keys; drop key histories memoized by earlier tests in this process
        $this->resetKeyCache();

        $db = Database::getInstance();
        $id = bin2hex(random_bytes(16));
        $db->query("INSERT INTO integrity_checks (id, user_id, status, progress, created_at, updated_at) VALUES (:id, :u, 'queued', 0, NOW(), NOW())", [
            ':id' => $id,
            ':u' => $this->fx['users']['admin'],
        ]);

        (new IntegrityCheckJob($id))->handle();

        // An audit that did not complete is a harness error, not a result
        $check = $db->query('SELECT status, results, error_message FROM integrity_checks WHERE id = :id', [':id' => $id])->fetch();
        $this->assertSame('completed', $check['status'], 'Audit failed: ' . (string) $check['error_message']);

        return json_decode($check['results'], true);
    }
}
