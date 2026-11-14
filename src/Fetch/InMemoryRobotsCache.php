<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * Process-local robots.txt cache. Lives as long as the object.
 */
final class InMemoryRobotsCache implements RobotsCache
{
    /** @var array<string, array{0: RobotsTxt, 1: float}> */
    private array $entries = [];

    public function get(string $origin, float $now): ?RobotsTxt
    {
        $e = $this->entries[$origin] ?? null;
        if ($e === null) {
            return null;
        }
        if ($e[1] <= $now) {
            unset($this->entries[$origin]);
            return null;
        }
        return $e[0];
    }

    public function set(string $origin, RobotsTxt $robots, float $expiresAt): void
    {
        $this->entries[$origin] = [$robots, $expiresAt];
    }
}
