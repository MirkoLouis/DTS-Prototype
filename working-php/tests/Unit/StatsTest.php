<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\Stats;

class StatsTest extends TestCase
{
    // Nearest-rank p95 of 1..100 is the 95th smallest value
    public function test_p95_of_1_to_100_is_95(): void
    {
        $stats = Stats::summarize(range(1, 100));
        $this->assertSame(95.0, $stats['p95']);
        $this->assertSame(100.0, $stats['max']);
        $this->assertSame(100, $stats['n']);
    }

    // An even count averages the two middle values
    public function test_median_of_even_count_is_average_of_middle_two(): void
    {
        $stats = Stats::summarize([4, 1, 3, 2]);
        $this->assertSame(2.5, $stats['median']);
        $this->assertSame(2.5, $stats['mean']);
    }
}
