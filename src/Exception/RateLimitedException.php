<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Exception;

/**
 * The host is rate limited: the next slot opens at retryAt, or the host
 * asks for a crawl delay longer than the configured maximum.
 */
final class RateLimitedException extends ReadableExtractException
{
    public function __construct(
        public readonly string $host,
        public readonly ?\DateTimeImmutable $retryAt,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'Rate limited: ' . $host);
    }
}
