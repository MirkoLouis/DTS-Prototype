<?php

namespace App\Modules\Users;

use App\Modules\Integrity\DigitalSignatureService;

/**
 * Proves that the signed-in user entered their Security PIN before a sensitive admin action.
 *
 * The PIN is proved the same way as for any signed action: it must unlock the user's signing key.
 * Wrong PINs count toward the PIN lockout, and a locked-out user is refused.
 */
class PinConfirmationService
{
    /**
     * Check the submitted PIN (a typed PIN or a cached token) by signing the action description.
     *
     * @return string|null null when the PIN is right, otherwise the message to show the user
     */
    public static function refusal(int $userId, ?string $submittedPin, string $actionDescription): ?string
    {
        // No PIN, or a cached PIN token that is no longer valid
        $pin = SecurityPinCacheService::resolvePin($submittedPin ?? '');
        if (empty($pin)) {
            return 'Security PIN is required.';
        }

        // Signing fails on a wrong PIN and while the user is locked out
        try {
            DigitalSignatureService::signAction($userId, $pin, $actionDescription);
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return null;
    }
}
