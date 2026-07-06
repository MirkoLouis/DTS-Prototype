<?php

namespace App\Controllers;

use App\Core\Database;

class SecurityKeyController
{
    public function store()
    {
        $pin = $_POST['pin'] ?? '';
        if (strlen($pin) < 6) {
            // redirect back with error
            header('Location: /dashboard');
            exit;
        }

        $db = Database::getInstance();
        $userId = $_SESSION['user_id'];
        
        // Mock generation of keys based on PIN for the prototype
        $publicKey = base64_encode(hash('sha256', $pin . $userId . 'public'));
        $privateKey = base64_encode(hash('sha256', $pin . $userId . 'private'));
        $now = date('Y-m-d H:i:s');

        $db->query("UPDATE users SET public_key = :pub, private_key = :priv, security_key_set_at = :now WHERE id = :id", [
            'pub' => $publicKey,
            'priv' => $privateKey,
            'now' => $now,
            'id' => $userId
        ]);

        $db->query("INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (:user_id, :pub, :now, :now2, :now3)", [
            'user_id' => $userId,
            'pub' => $publicKey,
            'now' => $now,
            'now2' => $now,
            'now3' => $now
        ]);

        header('Location: /dashboard');
        exit;
    }
}
