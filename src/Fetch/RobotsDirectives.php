<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Internal\Dom;
use IndianOpinion\ReadableExtract\Internal\HtmlParser;

/**
 * Robots directives read from the X-Robots-Tag header and robots meta tags
 * (case 72). noindex is reported only. The honoured set (by default none,
 * noarchive, nosnippet, noai, noimageai) decides what the extractor may
 * return: noimageai drops the lead image only; any other honoured
 * directive drops the body.
 */
final class RobotsDirectives
{
    public const DEFAULT_HONOURED = ['none', 'noarchive', 'nosnippet', 'noai', 'noimageai'];
    public const REQUIRED_HONOURED = ['none', 'noarchive'];

    /**
     * @param list<string> $directives every directive seen, lowercased
     * @param list<string> $honoured   directives that refuse content
     */
    public function __construct(public readonly array $directives = [], private readonly array $honoured = self::DEFAULT_HONOURED)
    {
    }

    /**
     * @param list<string>              $headerValues X-Robots-Tag values
     * @param list<string>              $honoured
     */
    public static function fromResponse(array $headerValues, string $html, string $productToken, array $honoured = self::DEFAULT_HONOURED): self
    {
        $token = strtolower($productToken);
        $found = [];
        foreach ($headerValues as $value) {
            foreach (self::parseHeader($value, $token) as $d) {
                $found[] = $d;
            }
        }
        if ($html !== '') {
            [$doc] = HtmlParser::parse(substr($html, 0, 262144));
            foreach (Dom::byTag($doc, 'meta') as $meta) {
                $name = strtolower(trim($meta->getAttribute('name')));
                if ($name === 'robots' || ($token !== '' && $name === $token)) {
                    foreach (explode(',', strtolower($meta->getAttribute('content'))) as $d) {
                        $d = trim($d);
                        if ($d !== '') {
                            $found[] = $d;
                        }
                    }
                }
            }
        }
        return new self(array_values(array_unique($found)), $honoured);
    }

    /** @return list<string> */
    private static function parseHeader(string $value, string $token): array
    {
        $out = [];
        $value = strtolower(trim($value));
        // "botname: noindex, nofollow" applies only to that bot.
        $m = [];
        if (preg_match('/^([a-z0-9_\-]+)\s*:\s*(.*)$/', $value, $m) === 1 && !in_array($m[1], ['unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview'], true)) {
            if ($m[1] !== $token) {
                return [];
            }
            $value = $m[2];
        }
        foreach (explode(',', $value) as $d) {
            $d = trim($d);
            if ($d !== '') {
                $out[] = $d;
            }
        }
        return $out;
    }

    public function has(string $directive): bool
    {
        return in_array(strtolower($directive), $this->directives, true);
    }

    public function noindex(): bool
    {
        return $this->has('noindex') || $this->has('none');
    }

    /** True when an honoured directive other than noimageai is present: no body. */
    public function refusesText(): bool
    {
        foreach ($this->honoured as $h) {
            if ($h !== 'noimageai' && $this->has($h)) {
                return true;
            }
        }
        return false;
    }

    /** True when images may not be used: no lead image. */
    public function refusesImages(): bool
    {
        return (in_array('noimageai', $this->honoured, true) && $this->has('noimageai')) || $this->refusesText();
    }
}
