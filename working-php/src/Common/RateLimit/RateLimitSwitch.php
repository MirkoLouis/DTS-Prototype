<?php

namespace App\Common\RateLimit;

/**
 * Reads the RATE_LIMIT_ENABLED setting. Rate limiting is off only for an explicit off value, so an empty value,
 * a blank or a typo can never switch protection off by accident.
 */
class RateLimitSwitch
{
    // Values (after trimming and lower-casing) that switch the limiter off
    private const OFF_VALUES = ['false', '0', 'off', 'no'];

    /**
     * Whether the limiter is on for a raw setting (null when the setting is absent).
     */
    public static function isOn(?string $raw): bool
    {
        // Absent means on
        if ($raw === null) {
            return true;
        }

        return !in_array(strtolower(trim($raw)), self::OFF_VALUES, true);
    }
}
