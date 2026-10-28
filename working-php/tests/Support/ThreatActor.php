<?php

namespace Tests\Support;

/**
 * Offline-LAN threat model. Every simulated attack (Tasks 9 and 10) is tagged with one of these.
 */
final class ThreatActor
{
    // An unauthenticated person at a kiosk or on a LAN browser
    public const KIOSK_WALKUP = 'kiosk_walkup';

    // A rogue or compromised device on the office network sending crafted HTTP requests
    public const LAN_DEVICE = 'lan_device';

    // A logged-in user acting outside their role or department, or using an unattended unlocked session
    public const STAFF_INSIDER = 'staff_insider';

    // Someone with direct database or server access
    public const PRIVILEGED_INSIDER = 'privileged_insider';

    // Internet-only threats, out of scope because the system is deployed on an offline LAN
    public const NOT_APPLICABLE = [
        'botnet DDoS',
        'internet credential stuffing',
        'cloud metadata SSRF',
    ];

    /**
     * All in-scope actors.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [self::KIOSK_WALKUP, self::LAN_DEVICE, self::STAFF_INSIDER, self::PRIVILEGED_INSIDER];
    }
}
