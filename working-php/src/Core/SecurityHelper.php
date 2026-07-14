<?php

namespace App\Core;

class SecurityHelper
{
    public static function cachePin(string $pin)
    {
        // NO-OP: PINs are no longer cached on the server to prevent session hijacking.
        // They are now securely held in browser sessionStorage.
    }

    public static function getCachedPin(): ?string 
    {
        return null;
    }
    
    public static function hasCachedPin(): bool
    {
        return false;
    }

    public static function clearCachedPin()
    {
        unset($_SESSION['cached_pin'], $_SESSION['pin_expires_at']);
    }

    public static function resolvePin(?string $submittedPin): ?string
    {
        // If the frontend sends 'CACHED_PIN', it's a legacy error since we removed server cache.
        if ($submittedPin === 'CACHED_PIN' || empty($submittedPin)) {
            return null;
        }
        
        return $submittedPin;
    }
}
