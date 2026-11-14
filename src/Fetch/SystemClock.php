<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }
}
