<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * Cache of parsed robots.txt per origin (scheme, host, port). Holds rules
 * only, never page content. Implement it over a shared store to share
 * robots.txt between workers.
 */
interface RobotsCache
{
    public function get(string $origin, float $now): ?RobotsTxt;

    public function set(string $origin, RobotsTxt $robots, float $expiresAt): void;
}
