<?php
define('BASE_PATH', dirname(__DIR__));
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

try {
    $db = Database::getInstance();
    $users = $db->query('SELECT id FROM users WHERE private_key IS NULL')->fetchAll();

    foreach ($users as $user) {
        $pin = 'password'; // Default PIN for key generation; in a real scenario, this should be securely handled
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Encrypt the private key with the PIN using AES-256-CBC
        $key = substr(hash('sha256', $pin), 0, 32);
        $iv = str_repeat('0', 16);
        $encryptedPriv = openssl_encrypt($secretKey, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        $pubB64 = base64_encode($publicKey);
        $privB64 = base64_encode($encryptedPriv);
        
        $now = date('Y-m-d H:i:s');

        $db->query("UPDATE users SET public_key = :pub, private_key = :priv, security_key_set_at = :now WHERE id = :id", [
            'pub' => $pubB64,
            'priv' => $privB64,
            'now' => $now,
            'id' => $user['id']
        ]);

        $db->query("INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (:user_id, :pub, :now, :now2, :now3)", [
            'user_id' => $user['id'],
            'pub' => $pubB64,
            'now' => $now,
            'now2' => $now,
            'now3' => $now
        ]);
    }
    
    echo "Successfully generated digital signatures for " . count($users) . " users.\n";
} catch (\Exception $e) {
    echo "Error generating keys: " . $e->getMessage() . "\n";
    exit(1);
}
