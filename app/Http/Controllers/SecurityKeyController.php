<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityKeyController extends Controller
{
    /**
     * Store the public key and encrypted private key for the authenticated user.
     * Generates a true Ed25519 keypair and encrypts the private key using a user-provided PIN.
     */
    public function store(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|min:4|max:16',
        ]);

        $user = Auth::user();
        
        // 1. Generate Ed25519 Keypair
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $privateKey = sodium_crypto_sign_secretkey($keypair);

        // 2. Derive an encryption key from the PIN using Argon2id (Sodium's default)
        // We use a 16-byte binary hash of the email as a deterministic salt for this prototype.
        $salt = substr(hash('sha256', $user->email, true), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $encryptionKey = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $request->pin,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );

        // 3. Encrypt the Private Key
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encryptedPrivateKey = sodium_crypto_secretbox($privateKey, $nonce, $encryptionKey);
        
        // Store as Base64 for database compatibility
        $user->public_key = base64_encode($publicKey);
        $user->private_key = base64_encode($nonce . $encryptedPrivateKey);
        $user->security_key_set_at = now();
        $user->save();

        return response()->json([
            'status' => 'success', 
            'message' => 'Your Department Security Key (Ed25519) has been generated and initialized successfully.',
            'public_key' => $user->public_key
        ]);
    }
}
