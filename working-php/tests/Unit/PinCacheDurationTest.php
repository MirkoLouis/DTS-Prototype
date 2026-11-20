<?php

namespace Tests\Unit;

use App\Modules\Users\SecurityPinCacheService;
use PHPUnit\Framework\TestCase;

/**
 * The Security PIN cache lasts four hours by default: one constant, used by the config fallback and the example .env.
 */
class PinCacheDurationTest extends TestCase
{
    // Four hours, in seconds
    private const FOUR_HOURS = 14400;

    // The single default is four hours
    public function test_default_duration_is_four_hours(): void
    {
        $this->assertSame(self::FOUR_HOURS, SecurityPinCacheService::DEFAULT_DURATION_SECONDS);
    }

    // The example .env ships the same value, so a new installation gets four hours
    public function test_env_example_sets_four_hours(): void
    {
        $values = [];
        // Read KEY=value lines, skipping comments
        foreach (file(BASE_PATH . '/.env.example', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim($value);
        }

        $this->assertSame((string) self::FOUR_HOURS, $values['PIN_CACHE_DURATION'] ?? null);
    }

    // Without PIN_CACHE_DURATION in .env, the config and the service both fall back to the constant (no second literal)
    public function test_fallbacks_use_the_constant(): void
    {
        $config = file_get_contents(BASE_PATH . '/src/Common/Config/app.config.php');
        $this->assertMatchesRegularExpression("/'pin_cache_duration' => \\(int\\) \\(\\\$env\\['PIN_CACHE_DURATION'\\] \\?\\? \\\\App\\\\Modules\\\\Users\\\\SecurityPinCacheService::DEFAULT_DURATION_SECONDS\\)/", $config);

        $service = file_get_contents(BASE_PATH . '/src/Modules/Users/SecurityPinCacheService.php');
        $this->assertStringContainsString("Config::get('app', 'pin_cache_duration', self::DEFAULT_DURATION_SECONDS)", $service);
        $this->assertStringNotContainsString("'pin_cache_duration', 900", $service);
    }
}
