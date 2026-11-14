<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Exception\NetworkException;

/**
 * Sends one HTTP GET and returns the response without following redirects
 * or storing cookies. Implementations throw NetworkException for DNS,
 * connection, TLS and read failures.
 */
interface Transport
{
    /** @throws NetworkException */
    public function send(TransportRequest $request): TransportResponse;
}
