<?php

namespace App\Controllers;

use App\Core\Database;

class SecurityKeyController
{
    public function store()
    {
        $pin = $_POST['pin'] ?? '';
        $pinConfirm = $_POST['pin_confirm'] ?? '';
        
        if (strlen($pin) < 6) {
            $_SESSION['error'] = "PIN must be at least 6 characters.";
            $this->redirectBasedOnRole();
        }

        if ($pin !== $pinConfirm) {
            $_SESSION['error'] = "PIN and Confirm PIN do not match.";
            $this->redirectBasedOnRole();
        }

        $db = Database::getInstance();
        $userId = $_SESSION['user_id'];
        
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
            'id' => $userId
        ]);

        $db->query("UPDATE user_public_key_histories SET deactivated_at = :now, updated_at = :now2 WHERE user_id = :id AND deactivated_at IS NULL", [
            'now' => $now,
            'now2' => $now,
            'id' => $userId
        ]);

        $db->query("INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (:user_id, :pub, :now, :now2, :now3)", [
            'user_id' => $userId,
            'pub' => $pubB64,
            'now' => $now,
            'now2' => $now,
            'now3' => $now
        ]);

        $_SESSION['success'] = "Digital Signature successfully generated and secured.";
        $this->redirectBasedOnRole();
    }

    private function redirectBasedOnRole()
    {
        $role = $_SESSION['role'] ?? 'staff';
        switch ($role) {
            case 'admin':
                header("Location: /admin-dashboard");
                break;
            case 'officer':
                header("Location: /intake");
                break;
            case 'staff':
            default:
                header("Location: /tasks");
                break;
        }
        exit;
    }
}
