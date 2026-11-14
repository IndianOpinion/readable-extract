<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Internal\Charset;
use IndianOpinion\ReadableExtract\PageContext;

/**
 * A fetched page, ready for Extractor::extract($page->body, $page->finalUrl, $page->toContext()).
 */
final class FetchedPage
{
    /**
     * @param array<string, list<string>> $headers lowercased names
     * @param list<string>                $hops    URLs that answered with a redirect, in order
     */
    public function __construct(
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $charset,
        public readonly string $body,
        public readonly array $hops,
        public readonly RobotsDirectives $robots,
        public readonly bool $robotsRefused,
        public readonly bool $truncated,
    ) {
    }

    public static function charsetFrom(?string $contentType): ?string
    {
        return $contentType === null ? null : Charset::fromContentType($contentType);
    }

    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)] ?? [];
        return $v === [] ? null : $v[0];
    }

    public function toContext(?\DateTimeImmutable $referenceTime = null): PageContext
    {
        return new PageContext(
            contentType: $this->header('content-type'),
            headers: $this->headers,
            referenceTime: $referenceTime,
            robots: $this->robots,
            bodyTruncated: $this->truncated,
        );
    }
}
