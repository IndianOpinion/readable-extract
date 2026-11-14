<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * Per-host request slots and blocks. Holds host names and times only.
 *
 * A shared implementation (for several workers) must make acquire()
 * atomic: check and reserve the slot in one step.
 */
interface RateLimitStore
{
    /**
     * Reserve the host's next slot when it is open at $now, and push the
     * next one to $now + $interval.
     *
     * @return float|null null when reserved, else the time the slot opens
     */
    public function acquire(string $host, float $now, float $interval): ?float;

    /** Block the host until $until (429 or 503 with Retry-After). Never shortens an existing block. */
    public function block(string $host, float $until): void;
}
