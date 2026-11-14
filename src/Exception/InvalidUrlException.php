<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Exception;

/**
 * The URL given is not an absolute http or https URL, is too long, or carries userinfo.
 */
final class InvalidUrlException extends ReadableExtractException
{
}
