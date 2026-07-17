<?php

namespace App\Core;

class SecurityHelper
{

    public static function resolvePin(?string $submittedPin): ?string
    {
        // If the frontend sends 'CACHED_PIN', it's a legacy error since we removed server cache.
        if ($submittedPin === 'CACHED_PIN' || empty($submittedPin)) {
            return null;
        }
        
        return $submittedPin;
    }
}
