<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * Downloads one page. Only ever called explicitly by the caller.
 */
interface Fetcher
{
    public function fetch(string $url): FetchedPage;
}
