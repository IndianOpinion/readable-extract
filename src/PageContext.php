<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

use IndianOpinion\ReadableExtract\Fetch\RobotsDirectives;

/**
 * What the caller knows about a page beyond its HTML: the response
 * headers, a reference time for relative dates, and robots directives.
 */
final class PageContext
{
    /** @var array<string, string> Header names lowercased, values joined with ", ". */
    public readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly ?string $contentType = null,
        array $headers = [],
        public readonly ?\DateTimeImmutable $referenceTime = null,
        public readonly ?RobotsDirectives $robots = null,
        public readonly bool $bodyTruncated = false,
    ) {
        $norm = [];
        foreach ($headers as $name => $value) {
            $norm[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : $value;
        }
        $this->headers = $norm;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The Content-Type value: the explicit property first, then the header. */
    public function contentTypeValue(): ?string
    {
        return $this->contentType ?? $this->header('content-type');
    }
}
