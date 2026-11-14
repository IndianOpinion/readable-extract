<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * Time source for the rate limiter and robots cache. Tests use a fake.
 */
interface Clock
{
    /** Seconds since the Unix epoch, with fractions. */
    public function now(): float;

    public function sleep(float $seconds): void;
}
