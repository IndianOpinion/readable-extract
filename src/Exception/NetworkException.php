<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Exception;

/**
 * DNS, connection, TLS or read failure. Never swallowed.
 */
final class NetworkException extends ReadableExtractException
{
}
