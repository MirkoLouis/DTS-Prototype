<?php

namespace App\Core;

class SecurityHelper
{
    public static function cachePin(string $pin)
    {
        $key = hash('sha256', session_id(), true);
        $iv = random_bytes(16);
        $encryptedPin = openssl_encrypt($pin, 'aes-256-cbc', $key, 0, $iv);
        
        $cacheDuration = getenv('PIN_CACHE_DURATION');
        if ($cacheDuration === false) {
            $cacheDuration = 14400; // 4 hours default
        } else {
            $cacheDuration = (int) $cacheDuration;
        }

        $_SESSION['cached_pin'] = base64_encode($iv . $encryptedPin);
        $_SESSION['pin_expires_at'] = time() + $cacheDuration;
    }

    public static function getCachedPin(): ?string 
    {
        if (isset($_SESSION['cached_pin']) && $_SESSION['pin_expires_at'] > time()) {
            $data = base64_decode($_SESSION['cached_pin']);
            $iv = substr($data, 0, 16);
            $encryptedPin = substr($data, 16);
            $key = hash('sha256', session_id(), true);
            
            return openssl_decrypt($encryptedPin, 'aes-256-cbc', $key, 0, $iv);
        }
        
        self::clearCachedPin();
        return null;
    }
    
    public static function hasCachedPin(): bool
    {
        if (isset($_SESSION['cached_pin']) && $_SESSION['pin_expires_at'] > time()) {
            return true;
        }
        self::clearCachedPin();
        return false;
    }

    public static function clearCachedPin()
    {
        unset($_SESSION['cached_pin'], $_SESSION['pin_expires_at']);
    }

    public static function resolvePin(?string $submittedPin): ?string
    {
        if ($submittedPin === 'CACHED_PIN') {
            return self::getCachedPin();
        }
        
        return $submittedPin;
    }
}
