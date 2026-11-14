<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Exception;

/**
 * The server answered with a status the fetcher does not accept.
 */
final class HttpStatusException extends ReadableExtractException
{
    public function __construct(public readonly int $status, string $url = '')
    {
        parent::__construct('HTTP ' . $status . ($url !== '' ? ' for ' . $url : ''), $status);
    }
}
