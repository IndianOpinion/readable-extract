<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Support;

use IndianOpinion\ReadableExtract\Fetch\Clock;

final class FakeClock implements Clock
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(public float $now = 1794000000.0)
    {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
