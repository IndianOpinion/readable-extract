<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

final class TransportRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
        public readonly float $connectTimeout,
        public readonly float $totalTimeout,
        public readonly int $bodyCap,
    ) {
    }
}
