<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Exception\NetworkException;

/**
 * Resolves a host name to IP addresses so they can be checked before any
 * request is sent.
 */
interface Resolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses
     * @throws NetworkException when the name does not resolve
     */
    public function resolve(string $host): array;
}
