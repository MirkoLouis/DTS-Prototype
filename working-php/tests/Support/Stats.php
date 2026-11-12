<?php

namespace Tests\Support;

/**
 * Descriptive statistics for timing samples (milliseconds).
 */
class Stats
{
    /**
     * Mean, median, p95 (nearest-rank: the ceil(0.95 * n)-th smallest) and max of the samples.
     *
     * @return array{n:int, mean:float, median:float, p95:float, max:float}
     */
    public static function summarize(array $milliseconds): array
    {
        // An empty sample set has no statistics
        $n = count($milliseconds);
        if ($n === 0) {
            throw new \InvalidArgumentException('Cannot summarize an empty sample set.');
        }

        $sorted = array_map('floatval', array_values($milliseconds));
        sort($sorted);

        // Median: middle value, or the average of the two middle values for an even count
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;

        // Nearest-rank p95
        $p95 = $sorted[(int) ceil(0.95 * $n) - 1];

        return [
            'n' => $n,
            'mean' => array_sum($sorted) / $n,
            'median' => (float) $median,
            'p95' => (float) $p95,
            'max' => $sorted[$n - 1],
        ];
    }
}
