<?php

namespace Tests\Benchmark;

use App\Core\Database;
use App\Core\IntegrityManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\Stats;
use Tests\Support\TestDatabase;
use Tests\Support\TestGuard;

/**
 * Performance benchmark for Chapter 4 (replaces scripts/benchmark-crypto.php).
 * Deliberately NOT based on Tests\TestCase: that class resets the database per test and would wipe the seeded data.
 * Knobs: BENCH_DOCS (default 1000000), BENCH_ITERATIONS (default 1000), BENCH_HTTP (default 1).
 */
#[Group('benchmark')]
class PerformanceBenchmarkTest extends TestCase
{
    // Paper target (Table 3.1): p95 below one second
    private const TARGET_P95_MS = 1000.0;

    // Read a positive integer knob from the environment
    private static function knob(string $name, int $default): int
    {
        $value = (int) getenv($name);

        return $value > 0 ? $value : $default;
    }

    // Run $fn $n times (after discarded warm-up calls) and return per-call durations in milliseconds;
    // $before runs untimed ahead of every call, warm-up included
    private static function measure(int $n, callable $fn, ?callable $before = null, int $warmup = 5): array
    {
        // Warm-up iterations are executed but never recorded
        for ($w = 0; $w < $warmup; $w++) {
            if ($before) {
                $before();
            }
            $fn($w);
        }

        $samples = [];
        for ($i = 0; $i < $n; $i++) {
            if ($before) {
                $before();
            }
            $start = hrtime(true);
            $fn($i);
            $samples[] = (hrtime(true) - $start) / 1e6;
        }

        return $samples;
    }

    // CPU model of the machine (host values, as /proc is not virtualized by the container)
    private static function cpuModel(): string
    {
        foreach (@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'model name')) {
                return trim(explode(':', $line, 2)[1]);
            }
        }

        return php_uname('m');
    }

    // Installed RAM in GB, rounded to one decimal
    private static function ramGb(): float
    {
        foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'MemTotal:')) {
                return round(((int) preg_replace('/\D/', '', $line)) / 1048576, 1);
            }
        }

        return 0.0;
    }

    // Make sure deped_dts_test holds BENCH_DOCS documents, seeding once and keeping the data
    private function ensureSeeded(int $target): void
    {
        $db = Database::getInstance();

        // Count what is there (a missing table counts as empty)
        try {
            $have = (int) $db->query('SELECT COUNT(*) AS c FROM documents')->fetch()['c'];
        } catch (\Throwable $e) {
            $have = 0;
        }
        if ($have >= $target) {
            return;
        }

        // fast-seed truncates and refills, so rebuild the schema and baseline users and seed the full target
        fwrite(STDERR, "\nSeeding {$target} documents into the test database (have {$have})...\n");
        TestDatabase::reset();
        TestDatabase::seedBaseline();
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/scripts/seeders/fast-seed.php') . ' ' . $target;
        $start = microtime(true);
        passthru('APP_ENV=testing ' . $cmd . ' > /dev/null', $code);
        fwrite(STDERR, sprintf("Seeding took %.1f s (exit %d)\n", microtime(true) - $start, $code));
        $this->assertSame(0, $code, 'fast-seed failed');
    }

    public function test_operations_meet_sub_second_target(): void
    {
        // Same guard as the rest of the suite: never touch a non-test database
        $config = require BASE_PATH . '/src/Config/config.php';
        TestGuard::assertSafe((string) $config['app']['env'], (string) $config['database']['dbname']);

        $docsTarget = self::knob('BENCH_DOCS', 1000000);
        $iterations = self::knob('BENCH_ITERATIONS', 1000);
        $argonIterations = min(100, $iterations);
        $withHttp = getenv('BENCH_HTTP') === false || getenv('BENCH_HTTP') === '' ? true : (bool) (int) getenv('BENCH_HTTP');

        $this->ensureSeeded($docsTarget);
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Sample documents that have logs, picked at random across the whole table
        $maxId = (int) $db->query('SELECT MAX(id) AS m FROM documents')->fetch()['m'];
        $sampleSize = 200;
        $documents = [];
        while (count($documents) < $sampleSize) {
            $row = $db->query('SELECT * FROM documents WHERE id >= :id ORDER BY id LIMIT 1', [':id' => random_int(1, max(1, $maxId))])->fetch();
            if ($row) {
                $documents[] = $row;
            }
        }
        $docCount = count($documents);
        $this->assertGreaterThan(0, $docCount, 'No documents in the test database');
        $doc = $documents[0];

        // Throwaway key wrapped exactly like a user's key (Argon2id + AES-256-CBC), never stored
        $pin = 'benchmark-pin';
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $iv = random_bytes(16);
        $aesKey = sodium_crypto_pwhash(32, $pin, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $wrappedKey = base64_encode($salt . $iv . openssl_encrypt($secretKey, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv));

        // Representative message-level inputs
        $stateHash = IntegrityManager::calculateStateHash($doc);
        $epoch = time();
        $message = IntegrityManager::signedMessage((int) $doc['id'], 'Processing Complete', $epoch, str_repeat('a', 64), $stateHash, 'Reviewed and signed.');
        $signature = base64_encode(sodium_crypto_sign_detached($message, $secretKey));
        $rawSignature = base64_decode($signature);

        // Each entry: operation => [samples, server label]
        $results = [];
        $cli = 'cli';

        // state_hash: SHA-256 over the nine document fields
        $results['state_hash'] = [self::measure($iterations, fn($i) => IntegrityManager::calculateStateHash($documents[$i % $docCount])), $cli];

        // chain_append: createLog inside a transaction that is always rolled back, so no rows remain
        $systemSigned = [];
        for ($i = -5; $i < $iterations; $i++) {
            $d = $documents[max(0, $i) % $docCount];
            $conn->beginTransaction();
            $start = hrtime(true);
            IntegrityManager::createLog((int) $d['id'], null, 'Benchmark Append', 'benchmark', $d);
            $elapsed = (hrtime(true) - $start) / 1e6;
            $conn->rollBack();
            // The first five iterations are discarded warm-up
            if ($i >= 0) {
                $systemSigned[] = $elapsed;
            }
        }
        $results['chain_append'] = [$systemSigned, $cli];

        // log_read: one random document's full history
        $results['log_read'] = [self::measure($iterations, fn($i) => $db->query('SELECT * FROM document_logs WHERE document_id = :id ORDER BY id ASC', [':id' => $documents[random_int(0, $docCount - 1)]['id']])->fetchAll()), $cli];

        // argon2id_unlock: PIN to unwrapped signing key (Argon2id derivation + AES decrypt) only
        $results['argon2id_unlock'] = [self::measure($argonIterations, fn($i) => IntegrityManager::decryptPrivateKey($wrappedKey, $pin)), $cli];

        // ed25519_sign / ed25519_verify
        $results['ed25519_sign'] = [self::measure($iterations, fn($i) => sodium_crypto_sign_detached($message, $secretKey)), $cli];
        $results['ed25519_verify'] = [self::measure($iterations, fn($i) => sodium_crypto_sign_verify_detached($rawSignature, $message, $publicKey)), $cli];

        // document_verify: recompute chain hash and check signature of every log of one document,
        // and fail the run if a seeded chain does not verify (same checks as IntegrityCheckJob)
        $verifyFailures = [];
        $results['document_verify'] = [self::measure($iterations, function ($i) use ($db, $documents, $docCount, &$verifyFailures) {
            $docId = $documents[$i % $docCount]['id'];
            $logs = $db->query('SELECT * FROM document_logs WHERE document_id = :id ORDER BY id ASC', [':id' => $docId])->fetchAll();
            $expectedPrevious = IntegrityManager::GENESIS_HASH;
            foreach ($logs as $log) {
                $userId = $log['user_id'] !== null ? (int) $log['user_id'] : null;
                $logEpoch = IntegrityManager::logTimestamp($log['created_at']);
                $remarks = (string) ($log['remarks'] ?? '');

                // Chain link: stored previous_hash must equal the parent, and the recomputed hash the stored hash
                $recomputed = IntegrityManager::chainHash((int) $log['document_id'], $userId, $log['action'], $logEpoch, $expectedPrevious, (string) $log['document_state_hash'], $remarks, (string) $log['signature']);
                if ($log['previous_hash'] !== $expectedPrevious || $recomputed !== $log['hash']) {
                    $verifyFailures[] = "doc {$docId} log {$log['id']}: chain hash/link mismatch";
                }

                // Signature over the full signed message
                $msg = IntegrityManager::signedMessage((int) $log['document_id'], $log['action'], $logEpoch, $expectedPrevious, (string) $log['document_state_hash'], $remarks);
                if (!IntegrityManager::verifySignature($userId, $log['created_at'], $msg, $log['signature'])['valid']) {
                    $verifyFailures[] = "doc {$docId} log {$log['id']}: invalid signature";
                }
                $expectedPrevious = $log['hash'];
            }
        }), $cli];
        $this->assertSame([], array_slice($verifyFailures, 0, 5), 'Seeded chains must verify');

        // active_guard_check: live state against the last sealed state
        $results['active_guard_check'] = [self::measure($iterations, fn($i) => IntegrityManager::verifyCurrentState($documents[$i % $docCount])), $cli];

        // HTTP page loads through the multi-worker built-in server, one logged-in client per role
        if ($withHttp) {
            $server = 'php-cli-server(workers=' . HttpClient::CLI_SERVER_WORKERS . ')';
            $codes = array_column($documents, 'tracking_code');
            $admin = HttpClient::start();
            $admin->loginAs('admin');
            $officer = HttpClient::start();
            $officer->loginAs('records.unit');
            $staff = HttpClient::start();
            $staff->loginAs('cash.unit');
            $guest = HttpClient::start();

            // Page rows: label => [client, path builder (iteration => path), untimed pre-step or null].
            // Only /admin-dashboard sits behind CacheMiddleware; the other pages render on every request.
            $clearCache = function () {
                foreach (glob(BASE_PATH . '/cache/responses-test/*') ?: [] as $cached) {
                    @unlink($cached);
                }
            };
            $pages = [
                'GET /admin-dashboard (cold)' => [$admin, fn($i) => '/admin-dashboard', $clearCache],
                'GET /admin-dashboard (warm)' => [$admin, fn($i) => '/admin-dashboard', null],
                'GET /intake' => [$officer, fn($i) => '/intake', null],
                'GET /tasks' => [$staff, fn($i) => '/tasks', null],
                'GET /track' => [$guest, fn($i) => '/track?codes=' . $codes[$i % count($codes)], null],
                'GET /documents/{code}' => [$admin, fn($i) => '/documents/' . $codes[$i % count($codes)], null],
            ];
            foreach ($pages as $label => [$client, $pathFor, $before]) {
                $results[$label] = [self::measure($iterations, function ($i) use ($client, $pathFor) {
                    $path = $pathFor($i);
                    $response = $client->get($path);
                    if ($response['status'] !== 200) {
                        throw new \RuntimeException("{$path} returned HTTP {$response['status']}");
                    }
                }, $before), $server];
            }
        }

        // Environment shared by every row
        $docsInDb = (int) $db->query('SELECT COUNT(*) AS c FROM documents')->fetch()['c'];
        $logsInDb = (int) $db->query('SELECT COUNT(*) AS c FROM document_logs')->fetch()['c'];
        $commit = trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(BASE_PATH)) . ' describe --always --dirty 2>/dev/null')) ?: 'unknown';
        $runAt = date('c');
        $cpu = self::cpuModel();
        $ram = self::ramGb();

        // Write the CSV
        $dir = BASE_PATH . '/storage/benchmarks';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $csvPath = $dir . '/benchmark-' . date('Ymd-His') . '.csv';
        $out = fopen($csvPath, 'w');
        fputcsv($out, ['operation', 'n', 'mean_ms', 'median_ms', 'p95_ms', 'max_ms', 'docs_in_db', 'logs_in_db', 'git_commit', 'php_version', 'server', 'run_at', 'cpu', 'ram_gb'], ',', '"', '\\');
        $failures = [];
        foreach ($results as $operation => [$samples, $server]) {
            $s = Stats::summarize($samples);
            fputcsv($out, [$operation, $s['n'], round($s['mean'], 4), round($s['median'], 4), round($s['p95'], 4), round($s['max'], 4), $docsInDb, $logsInDb, $commit, PHP_VERSION, $server, $runAt, $cpu, $ram], ',', '"', '\\');
            fwrite(STDERR, sprintf("%-24s n=%-5d mean=%9.4f median=%9.4f p95=%9.4f max=%9.4f\n", $operation, $s['n'], $s['mean'], $s['median'], $s['p95'], $s['max']));
            if ($s['p95'] >= self::TARGET_P95_MS) {
                $failures[] = "{$operation} p95 {$s['p95']} ms";
            }
        }
        fclose($out);
        fwrite(STDERR, "CSV: {$csvPath}\nHardware: {$cpu}, {$ram} GB RAM; docs={$docsInDb}, logs={$logsInDb}\n");

        // Every operation must meet the sub-second p95 target
        $this->assertSame([], $failures, 'Operations over the 1000 ms p95 target');
    }
}
