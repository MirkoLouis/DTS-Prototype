<?php

/**
 * Objective 3 benchmark: computational cost of log hashing, log reading and signature generation.
 *
 * Times each Trust Builder operation in isolation against the seeded database, using the same
 * code paths as the live system (IntegrityManager). Nothing is written to the database.
 *
 * Usage: php scripts/benchmark-crypto.php [--iterations=1000] [--argon-iterations=50]
 * Output: a summary table on screen and a CSV in storage/benchmarks/.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set('Asia/Manila');

use App\Core\Database;
use App\Core\IntegrityManager;

// Parse --name=value options
$options = getopt('', ['iterations::', 'argon-iterations::']);
$iterations = max(1, (int) ($options['iterations'] ?? 1000));
$argonIterations = max(1, (int) ($options['argon-iterations'] ?? 50));

/**
 * Run $fn $n times and return per-call durations in milliseconds.
 */
function measure(int $n, callable $fn): array
{
    $samples = [];
    for ($i = 0; $i < $n; $i++) {
        $start = hrtime(true);
        $fn($i);
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
    return $samples;
}

/**
 * Summarize samples as mean / p50 / p95 / min / max in milliseconds.
 */
function summarize(array $samples): array
{
    sort($samples);
    $n = count($samples);
    $pick = fn(float $q) => $samples[min($n - 1, (int) ceil($q * $n) - 1)];
    return [
        'n' => $n,
        'mean' => array_sum($samples) / $n,
        'p50' => $pick(0.50),
        'p95' => $pick(0.95),
        'min' => $samples[0],
        'max' => $samples[$n - 1]
    ];
}

$db = Database::getInstance();

// Sample real documents so hashing and reads run on production-shaped data
$documents = $db->query("SELECT * FROM documents ORDER BY RAND() LIMIT 200")->fetchAll();
if (empty($documents)) {
    fwrite(STDERR, "No documents found. Seed the database first (composer run seed:fast).\n");
    exit(1);
}
$docCount = count($documents);

// A throwaway key wrapped exactly like a user's key (Argon2id + AES-256-CBC), never stored
$pin = 'benchmark-pin';
$keypair = sodium_crypto_sign_keypair();
$secretKey = sodium_crypto_sign_secretkey($keypair);
$publicKey = sodium_crypto_sign_publickey($keypair);
$salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
$iv = random_bytes(16);
$aesKey = sodium_crypto_pwhash(32, $pin, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
$wrappedKey = base64_encode($salt . $iv . openssl_encrypt($secretKey, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv));

// Representative inputs for the message-level operations
$doc = $documents[0];
$stateHash = IntegrityManager::calculateStateHash($doc);
$epoch = time();
$message = IntegrityManager::signedMessage((int) $doc['id'], 'Processing Complete', $epoch, str_repeat('a', 64), $stateHash, 'Reviewed and signed.');
$signature = base64_encode(sodium_crypto_sign_detached($message, $secretKey));

echo "Benchmarking with {$iterations} iterations ({$argonIterations} for Argon2id-bound operations) over {$docCount} sampled documents...\n\n";

// Each entry: [group, operation, samples]
$results = [];

// Log hashing
$results[] = ['Hashing', 'State hash (SHA-256, 9 fields)', measure($iterations, fn($i) => IntegrityManager::calculateStateHash($documents[$i % $docCount]))];
$results[] = ['Hashing', 'Chain hash (SHA-256, 8 fields)', measure($iterations, fn($i) => IntegrityManager::chainHash((int) $doc['id'], 2, 'Processing Complete', $epoch, str_repeat('a', 64), $stateHash, 'Reviewed and signed.', $signature))];

// Log reading
$results[] = ['Reading', 'Parent hash lookup (indexed SELECT)', measure($iterations, fn($i) => $db->query("SELECT hash FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1", [':id' => $documents[$i % $docCount]['id']])->fetch())];
$results[] = ['Reading', 'Full chain read (one document)', measure($iterations, fn($i) => $db->query("SELECT * FROM document_logs WHERE document_id = :id ORDER BY id ASC", [':id' => $documents[$i % $docCount]['id']])->fetchAll())];
$results[] = ['Reading', 'Active Guard (verifyCurrentState)', measure($iterations, fn($i) => IntegrityManager::verifyCurrentState($documents[$i % $docCount]))];

// Signature generation and verification
$results[] = ['Signing', 'Argon2id key derivation (INTERACTIVE)', measure($argonIterations, fn($i) => sodium_crypto_pwhash(32, $pin, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13))];
$results[] = ['Signing', 'AES-256-CBC private key decrypt', measure($iterations, fn($i) => openssl_decrypt(substr(base64_decode($wrappedKey), 32), 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv))];
$results[] = ['Signing', 'Ed25519 sign', measure($iterations, fn($i) => sodium_crypto_sign_detached($message, $secretKey))];
$results[] = ['Signing', 'Ed25519 verify', measure($iterations, fn($i) => sodium_crypto_sign_verify_detached(base64_decode($signature), $message, $publicKey))];
$results[] = ['Signing', 'PIN to signature (unwrap + sign)', measure($argonIterations, fn($i) => sodium_crypto_sign_detached($message, IntegrityManager::decryptPrivateKey($wrappedKey, $pin)))];

// Print the summary table
printf("%-9s %-40s %6s %10s %10s %10s %10s %10s\n", 'Group', 'Operation', 'n', 'mean ms', 'p50 ms', 'p95 ms', 'min ms', 'max ms');
echo str_repeat('-', 112) . "\n";
$rows = [];
foreach ($results as [$group, $operation, $samples]) {
    $s = summarize($samples);
    printf("%-9s %-40s %6d %10.4f %10.4f %10.4f %10.4f %10.4f\n", $group, $operation, $s['n'], $s['mean'], $s['p50'], $s['p95'], $s['min'], $s['max']);
    $rows[] = array_merge([$group, $operation], array_values($s));
}

// Record the environment alongside the numbers so results are reproducible
$mysqlVersion = $db->query("SELECT VERSION() AS v")->fetch()['v'] ?? 'unknown';
$logCount = $db->query("SELECT COUNT(*) AS c FROM document_logs")->fetch()['c'] ?? 0;
$cpu = trim((string) @shell_exec("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2")) ?: php_uname('m');
$environment = [
    'run_at' => date('c'),
    'php' => PHP_VERSION,
    'libsodium' => SODIUM_LIBRARY_VERSION,
    'mysql' => $mysqlVersion,
    'cpu' => $cpu,
    'document_logs_rows' => $logCount
];

// Write the CSV
$dir = BASE_PATH . '/storage/benchmarks';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$csvPath = $dir . '/benchmark-' . date('Ymd-His') . '.csv';
$out = fopen($csvPath, 'w');
foreach ($environment as $key => $value) {
    fputcsv($out, ["# {$key}", $value]);
}
fputcsv($out, ['group', 'operation', 'n', 'mean_ms', 'p50_ms', 'p95_ms', 'min_ms', 'max_ms']);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);

echo "\nEnvironment: PHP {$environment['php']}, libsodium {$environment['libsodium']}, MySQL {$mysqlVersion}, CPU {$cpu}, {$logCount} log rows.\n";
echo "CSV written: {$csvPath}\n";
