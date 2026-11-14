<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Exception;

/**
 * A fetch was refused or failed for a reason other than HTTP status,
 * network, robots or rate limits. The reason code is stable.
 */
final class FetchException extends ReadableExtractException
{
    public const REDIRECT_LOOP = 'redirect_loop';
    public const TOO_MANY_REDIRECTS = 'too_many_redirects';
    public const UNEXPECTED_CONTENT_TYPE = 'unexpected_content_type';
    public const BAD_REDIRECT = 'bad_redirect';

    public function __construct(public readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
