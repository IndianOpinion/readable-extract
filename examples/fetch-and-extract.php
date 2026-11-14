<?php

/*
 * Fetch one page politely, then extract it. This is the only example that
 * touches the network, and only when you pass a URL and a user agent.
 *
 *     php examples/fetch-and-extract.php <url> "YourProduct/1.0 (+https://your.site/contact)"
 *
 * robots.txt is always honoured, one request per host every 10 seconds,
 * and 429 or 503 with Retry-After stop the host until the time given.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\ReadableExtract\Exception\RateLimitedException;
use IndianOpinion\ReadableExtract\Exception\ReadableExtractException;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\Fetch\FetchOptions;
use IndianOpinion\ReadableExtract\Fetch\HttpFetcher;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php examples/fetch-and-extract.php <url> \"Product/1.0 (+https://contact.example)\"\n");
    exit(2);
}

$logger = static function (string $level, string $message, array $context): void {
    fwrite(STDERR, sprintf("[%s] %s %s\n", $level, $message, json_encode($context, JSON_UNESCAPED_SLASHES)));
};

try {
    $fetcher = new HttpFetcher(new FetchOptions($argv[2]), null, null, null, $logger);
    $page = $fetcher->fetch($argv[1]);
    $article = (new Extractor())->extract($page->body, $page->finalUrl, $page->toContext());
} catch (RateLimitedException $e) {
    fwrite(STDERR, 'Rate limited; try again after ' . ($e->retryAt?->format(DATE_ATOM) ?? 'the crawl delay allows') . "\n");
    exit(3);
} catch (ReadableExtractException $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(3);
}

echo json_encode($article, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
