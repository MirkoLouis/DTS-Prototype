<?php

namespace Tests\Unit;

use App\Research\Barrier;
use App\Research\BarrierWorkerException;
use PHPUnit\Framework\TestCase;

/**
 * Barrier failure reporting used by the live concurrency script.
 */
class BarrierTest extends TestCase
{
    // A failing worker raises BarrierWorkerException carrying the other workers' results; no child stays tracked
    public function test_worker_failure_keeps_partial_results(): void
    {
        try {
            Barrier::forkAll(3, function (int $index): array {
                // Worker 1 fails; the others succeed
                if ($index === 1) {
                    throw new \RuntimeException('boom');
                }
                return ['index' => $index];
            });
            $this->fail('No exception for a failing worker.');
        } catch (BarrierWorkerException $e) {
            $this->assertSame([0, 2], array_keys($e->results));
            $this->assertSame(2, $e->results[2]['index']);
            $this->assertStringContainsString('worker 1: RuntimeException: boom', $e->getMessage());
        }

        // Every child was reaped, so an interrupted-run kill has nothing left to do
        $this->assertSame([], Barrier::activePids());
        $this->assertSame(0, Barrier::killActive(0.1));
    }
}
