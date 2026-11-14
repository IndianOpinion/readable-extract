<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Support;

use IndianOpinion\ReadableExtract\Exception\NetworkException;
use IndianOpinion\ReadableExtract\Fetch\Resolver;

final class FakeResolver implements Resolver
{
    /** A public address outside every reserved range. */
    public const PUBLIC = '100.0.0.1';

    /** @param array<string, list<string>|null> $map host => addresses, null means NXDOMAIN */
    public function __construct(private array $map = [])
    {
    }

    public function resolve(string $host): array
    {
        if (array_key_exists($host, $this->map)) {
            if ($this->map[$host] === null) {
                throw new NetworkException('DNS lookup failed for ' . $host);
            }
            return $this->map[$host];
        }
        return [self::PUBLIC];
    }
}
