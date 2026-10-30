<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/vendor/autoload.php';

// Key periods must use the same clock as log timestamps
date_default_timezone_set(\App\Integrity\HashChainMicroShardingBuilder::APP_TIMEZONE);

use App\Common\Database\Database;

/**
 * Write or replace KEY=value lines in .env, keeping every other line untouched.
 */
function writeEnvValues(string $envPath, array $values): void
{
    $lines = file_exists($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];

    // Replace existing keys in place
    foreach ($lines as $i => $line) {
        foreach ($values as $key => $value) {
            if (str_starts_with(trim($line), $key . '=')) {
                $lines[$i] = "{$key}={$value}";
                unset($values[$key]);
            }
        }
    }

    // Append keys that were not present
    foreach ($values as $key => $value) {
        $lines[] = "{$key}={$value}";
    }

    file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
}

// --system: create the server's Ed25519 key for system-initiated log entries (skips if one exists)
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && in_array('--system', $argv ?? [], true)) {
    $force = in_array('--force', $argv, true);
    $existing = \App\Common\Config\Config::get('app', 'system_signing_secret', '');

    if ($existing !== '' && !$force) {
        echo "System signing key already present in .env (use --force to replace it; old logs will then fail verification).\n";
        exit(0);
    }

    $keypair = sodium_crypto_sign_keypair();
    // Under APP_ENV=testing the key goes into .env.testing, never the operational .env
    $targetEnv = getenv('APP_ENV') === 'testing' ? '/.env.testing' : '/.env';
    writeEnvValues(BASE_PATH . $targetEnv, [
        'SYSTEM_SIGNING_SECRET' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        'SYSTEM_SIGNING_PUBLIC' => base64_encode(sodium_crypto_sign_publickey($keypair))
    ]);
    echo "System signing key written to " . ltrim($targetEnv, '/') . ".\n";
    exit(0);
}

try {
    $db = Database::getInstance();
    $users = $db->query('SELECT id FROM users WHERE private_key IS NULL')->fetchAll();

    foreach ($users as $user) {
        $pin = 'password'; // Default PIN for key generation; in a real scenario, this should be securely handled
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Encrypt the private key with the PIN using Argon2id and AES-256-CBC
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            32, 
            $pin, 
            $salt, 
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, 
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, 
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        $iv = random_bytes(16);
        $encryptedPriv = openssl_encrypt($secretKey, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        $pubB64 = base64_encode($publicKey);
        $privB64 = base64_encode($salt . $iv . $encryptedPriv);
        
        $now = date('Y-m-d H:i:s');

        $db->query("UPDATE users SET public_key = :pub, private_key = :priv, security_key_set_at = :now WHERE id = :id", [
            'pub' => $pubB64,
            'priv' => $privB64,
            'now' => $now,
            'id' => $user['id']
        ]);

        // A user's first key covers all seeded history (seeders sign past-dated logs with it);
        // a later key starts now, so it never overlaps a key that already signed logs
        $hasHistory = (bool) $db->query("SELECT 1 FROM user_public_key_histories WHERE user_id = :id LIMIT 1", ['id' => $user['id']])->fetch();
        $activatedAt = $hasHistory ? $now : '2000-01-01 00:00:00';

        $db->query("INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (:user_id, :pub, :activated, :now2, :now3)", [
            'user_id' => $user['id'],
            'pub' => $pubB64,
            'activated' => $activatedAt,
            'now2' => $now,
            'now3' => $now
        ]);
    }
    
    echo "Successfully generated digital signatures for " . count($users) . " users.\n";
} catch (\Exception $e) {
    echo "Error generating keys: " . $e->getMessage() . "\n";
    exit(1);
}
