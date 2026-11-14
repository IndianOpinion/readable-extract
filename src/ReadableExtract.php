<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

use IndianOpinion\ReadableExtract\Fetch\FetchOptions;
use IndianOpinion\ReadableExtract\Fetch\HttpFetcher;
use IndianOpinion\ReadableExtract\Fetch\Transport;

/**
 * Convenience entry point: fetch one page, then extract it.
 *
 * Each call builds a fresh HttpFetcher, so its in-memory robots cache and
 * rate limiter last for that call only. For more than one page, build one
 * HttpFetcher and reuse it.
 */
final class ReadableExtract
{
    public static function fromUrl(
        string $url,
        FetchOptions $fetch,
        ?ExtractorOptions $extract = null,
        ?Transport $transport = null,
    ): Article {
        $page = (new HttpFetcher($fetch, $transport))->fetch($url);
        return (new Extractor($extract))->extract($page->body, $page->finalUrl, $page->toContext());
    }
}
