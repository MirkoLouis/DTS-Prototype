<?php

namespace App\Research;

use RuntimeException;

/**
 * Thrown by Barrier::forkAll when some workers failed, wrote no result or hit the deadline.
 * Carries the results of the workers that did finish, keyed by worker index.
 */
class BarrierWorkerException extends RuntimeException
{
    /**
     * @param array<int, array> $results results of the workers that finished
     */
    public function __construct(string $message, public readonly array $results = [])
    {
        parent::__construct($message);
    }
}
