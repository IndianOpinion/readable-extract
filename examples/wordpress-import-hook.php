<?php

/*
 * Sketch: using the extractor from a WordPress import hook. Copy the idea
 * into your own plugin; this file only runs inside WordPress.
 *
 * The importer already has the HTML, so nothing is fetched. Your own
 * hosts go into ownHosts so a copy on your site is never credited as the
 * source. Weak results are saved as drafts for a human.
 */

declare(strict_types=1);

if (!function_exists('add_filter')) {
    fwrite(STDERR, "This example runs inside WordPress only.\n");
    exit(0);
}

require_once __DIR__ . '/../bootstrap.php';

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;

add_filter('my_importer_item', static function (array $item): array {
    static $extractor = null;
    $extractor ??= new Extractor(
        (new ExtractorOptions())->withOwnHosts([(string) wp_parse_url(home_url(), PHP_URL_HOST)]),
    );
    $article = $extractor->extract((string) $item['html'], (string) $item['url']);

    $item['post_title'] = $article->title ?? $item['post_title'];
    $item['post_content'] = wp_kses_post($article->bodyHtml); // run your own sanitiser too
    $item['post_status'] = $article->isReadable() ? $item['post_status'] : 'draft';
    $item['meta']['source_url'] = $article->canonicalUrl;
    $item['meta']['extract_warnings'] = array_map(static fn ($w) => $w->value, $article->warnings);
    return $item;
});
