<?php

namespace Tests\Unit;

use App\Core\TrackingCode;
use PHPUnit\Framework\TestCase;

class TrackingCodeTest extends TestCase
{
    // Every generated code must match the agreed DEPED-XXXXXXXXXX format
    public function test_format_matches_pattern(): void
    {
        // Generate a thousand codes and check each against the shared pattern
        for ($i = 0; $i < 1000; $i++) {
            $this->assertMatchesRegularExpression(TrackingCode::PATTERN, TrackingCode::generate());
        }
    }

    // The generator must draw from the CSPRNG only, never from the clock
    public function test_no_time_based_input(): void
    {
        $source = file_get_contents(BASE_PATH . '/src/Core/TrackingCode.php');

        // Strip comments so explanatory text cannot trip the check
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringContainsString('random_bytes', $code);
        $this->assertStringNotContainsString('uniqid', $code);
        $this->assertStringNotContainsString('microtime', $code);
    }
}
