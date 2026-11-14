<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Exception;

/**
 * robots.txt does not allow the path, or could not be read (5xx, timeout, network failure).
 */
final class RobotsDisallowedException extends ReadableExtractException
{
}
