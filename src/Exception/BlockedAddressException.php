<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Exception;

/**
 * The host resolves to a private, loopback, link-local, reserved or metadata address, or the port is not allowed.
 */
final class BlockedAddressException extends ReadableExtractException
{
}
