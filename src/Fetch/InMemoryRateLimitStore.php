<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * Process-local rate-limit store.
 */
final class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, float> host => time the next slot opens */
    private array $next = [];

    public function acquire(string $host, float $now, float $interval): ?float
    {
        $open = $this->next[$host] ?? 0.0;
        if ($open > $now) {
            return $open;
        }
        $this->next[$host] = $now + $interval;
        return null;
    }

    public function block(string $host, float $until): void
    {
        $this->next[$host] = max($this->next[$host] ?? 0.0, $until);
    }
}
