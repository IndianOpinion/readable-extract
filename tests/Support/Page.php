<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Support;

/**
 * Builders for small synthetic pages. Reserved example hosts only.
 */
final class Page
{
    public const URL = 'https://www.daily.example/city/story-1001';

    private const EN = [
        'The council met on Tuesday to discuss the drainage plan, and members asked for a clear timeline.',
        'Officials said work would begin in the eastern wards first, where water collected last year.',
        'Residents welcomed the plan, but several asked how the cost would be shared between the wards.',
        'The commissioner said a detailed report would be placed before the council next month.',
    ];

    /** English prose of at least $chars characters, in paragraphs. */
    public static function prose(int $chars = 900, int $offset = 0): string
    {
        $out = [];
        $len = 0;
        $i = $offset;
        while ($len < $chars) {
            $p = self::EN[$i % 4] . ' ' . self::EN[($i + 1) % 4];
            $out[] = '<p>' . $p . '</p>';
            $len += strlen($p) + 1;
            $i++;
        }
        return implode("\n", $out);
    }

    /** Plain text of prose() for comparisons. */
    public static function proseText(int $chars = 900, int $offset = 0): string
    {
        return trim(strip_tags(str_replace('</p>', "\n\n", self::prose($chars, $offset))));
    }

    /**
     * @param array<string, mixed>|null $jsonLd
     */
    public static function html(string $body, ?array $jsonLd = null, string $head = '', string $lang = 'en', string $htmlAttr = ''): string
    {
        $ld = '';
        if ($jsonLd !== null) {
            $ld = '<script type="application/ld+json">'
                . json_encode($jsonLd + ['@context' => 'https://schema.org'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '</script>';
        }
        return "<!DOCTYPE html>\n<html lang=\"$lang\"$htmlAttr><head><meta charset=\"utf-8\"><title>Test page</title>$ld$head</head><body>$body</body></html>";
    }

    /**
     * A NewsArticle page with a visible body.
     *
     * @param array<string, mixed> $ld extra JSON-LD fields
     */
    public static function article(string $bodyHtml, array $ld = [], string $head = '', string $lang = 'en'): string
    {
        return self::html(
            '<main><article class="story"><h1>Council asks for a timeline</h1><div class="story-content">' . $bodyHtml . '</div></article></main>',
            $ld + ['@type' => 'NewsArticle', 'headline' => 'Council asks for a timeline'],
            $head,
            $lang,
        );
    }
}
