<?php

/*
 * Extract an article from HTML you already have. No network access.
 *
 *     php examples/extract-offline.php [file.html] [url]
 *
 * With no arguments it uses one of the bundled synthetic fixtures.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;

$file = $argv[1] ?? __DIR__ . '/../fixtures/lang-hi/page.html';
$url = $argv[2] ?? 'https://www.hi-daily.example/city/rain-pumps-2026-11-10-hi';

$html = file_get_contents($file);
if ($html === false) {
    fwrite(STDERR, "Cannot read $file\n");
    exit(2);
}

$extractor = new Extractor(
    (new ExtractorOptions())
        ->withOwnHosts(['www.my-site.example'])   // never credit your own site as the source
        ->withTrace(),
);
$article = $extractor->extract($html, $url);

printf("Type:       %s\n", $article->pageType->value);
printf("Title:      %s\n", $article->title ?? '-');
printf("Authors:    %s\n", implode(', ', array_map(static fn ($a) => $a->name, $article->authors)) ?: '-');
printf("Published:  %s\n", $article->publishedAt?->format(DATE_ATOM) ?? '-');
printf("Lead image: %s\n", $article->leadImage?->url ?? '-');
printf("Canonical:  %s\n", $article->canonicalUrl ?? '-');
printf("Language:   %s (script %s)\n", $article->language ?? '-', $article->detectedScript ?? '-');
printf("Length:     %d characters, %d words (%s)\n", $article->charCount, $article->wordCount, $article->wordCountMethod);
printf("Confidence: %.2f, readable: %s\n", $article->confidence, $article->isReadable() ? 'yes' : 'no, hold for a human');
printf("Warnings:   %s\n\n", implode(', ', array_map(static fn ($w) => $w->value, $article->warnings)) ?: '-');
echo $article->bodyText, "\n";
