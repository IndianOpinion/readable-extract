<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Reads structured data from a parsed page: JSON-LD blocks, microdata,
 * meta tags, link relations, the html element's lang and AMP marker, and
 * base href. Read once, before any element is removed.
 *
 * @internal
 */
final class Metadata
{
    public const ARTICLE_TYPES = [
        'article', 'newsarticle', 'reportagenewsarticle', 'analysisnewsarticle', 'opinionnewsarticle',
        'backgroundnewsarticle', 'reviewnewsarticle', 'askpublicnewsarticle', 'blogposting',
        'satiricalarticle', 'report', 'techarticle', 'advertisercontentarticle', 'socialmediaposting',
        'liveblogposting',
    ];

    /** @var list<array<string, mixed>> flattened typed JSON-LD nodes */
    public array $nodes = [];

    /** @var array<string, mixed>|null the chosen article-typed node */
    public ?array $article = null;

    public ?string $structuredType = null;

    /** @var array<string, list<string>> meta key (property, name or itemprop, lowercased) => contents */
    public array $meta = [];

    /** @var array<string, list<array{href: string, text: string}>> rel => links */
    public array $links = [];

    /** @var array<string, list<\DOMElement>> itemprop => elements */
    public array $itemprops = [];

    public bool $microdataArticle = false;
    public ?string $htmlLang = null;
    public bool $amp = false;
    public ?string $baseHref = null;
    public int $jsonLdBlocks = 0;
    public int $jsonLdErrors = 0;

    public static function read(\DOMDocument $doc): self
    {
        $m = new self();
        $html = $doc->getElementsByTagName('html')->item(0);
        if ($html instanceof \DOMElement) {
            $lang = trim($html->getAttribute('lang'));
            if ($lang === '') {
                $lang = trim($html->getAttribute('xml:lang'));
            }
            $m->htmlLang = $lang !== '' ? $lang : null;
            $m->amp = $html->hasAttribute('amp') || $html->hasAttribute("\u{26A1}");
        }
        foreach (Dom::all($doc) as $el) {
            $name = Dom::name($el);
            if ($name === 'script') {
                if (str_contains(strtolower($el->getAttribute('type')), 'ld+json')) {
                    $m->addJsonLd($el->textContent);
                }
                continue;
            }
            if ($name === 'meta') {
                $content = $el->getAttribute('content');
                foreach (['property', 'name', 'itemprop', 'http-equiv'] as $attr) {
                    $key = strtolower(trim($el->getAttribute($attr)));
                    if ($key !== '' && $el->hasAttribute('content')) {
                        $m->meta[$key][] = Text::line($content);
                    }
                }
            } elseif ($name === 'link' || ($name === 'a' && $el->hasAttribute('rel'))) {
                foreach (preg_split('/\s+/', strtolower(trim($el->getAttribute('rel')))) ?: [] as $rel) {
                    if ($rel !== '' && $el->hasAttribute('href')) {
                        $m->links[$rel][] = ['href' => trim($el->getAttribute('href')), 'text' => Dom::text($el)];
                    }
                }
            } elseif ($name === 'base' && $m->baseHref === null && $el->hasAttribute('href')) {
                $m->baseHref = trim($el->getAttribute('href'));
            }
            if ($el->hasAttribute('itemprop')) {
                foreach (preg_split('/\s+/', trim($el->getAttribute('itemprop'))) ?: [] as $prop) {
                    if ($prop !== '') {
                        $m->itemprops[strtolower($prop)][] = $el;
                    }
                }
            }
            if ($el->hasAttribute('itemtype')) {
                $t = strtolower($el->getAttribute('itemtype'));
                foreach (self::ARTICLE_TYPES as $at) {
                    if (str_ends_with(rtrim($t, '/ '), '/' . $at)) {
                        $m->microdataArticle = true;
                    }
                }
            }
        }
        $m->chooseArticle();
        return $m;
    }

    public function firstMeta(string ...$keys): ?string
    {
        foreach ($keys as $k) {
            foreach ($this->meta[strtolower($k)] ?? [] as $v) {
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return null;
    }

    /** Value of an itemprop: content, datetime, href or src, else the text. */
    public function itempropValue(string $prop): ?string
    {
        foreach ($this->itemprops[strtolower($prop)] ?? [] as $el) {
            $v = self::elementValue($el);
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    public static function elementValue(\DOMElement $el): string
    {
        foreach (['content', 'datetime'] as $a) {
            if ($el->hasAttribute($a)) {
                return Text::line($el->getAttribute($a));
            }
        }
        $n = Dom::name($el);
        if (($n === 'a' || $n === 'link') && $el->hasAttribute('href')) {
            return trim($el->getAttribute('href'));
        }
        if ($n === 'img' && $el->hasAttribute('src')) {
            return trim($el->getAttribute('src'));
        }
        return Dom::text($el);
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string> lowercased short type names
     */
    public static function types(array $node): array
    {
        $t = $node['@type'] ?? [];
        $list = is_array($t) ? $t : [$t];
        $out = [];
        foreach ($list as $one) {
            if (is_string($one) && $one !== '') {
                $one = strtolower($one);
                $slash = strrpos($one, '/');
                if ($slash !== false) {
                    $one = substr($one, $slash + 1);
                }
                if (str_starts_with($one, 'schema:')) {
                    $one = substr($one, 7);
                }
                $out[] = $one;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $node */
    public static function isArticleNode(array $node): bool
    {
        foreach (self::types($node) as $t) {
            if (in_array($t, self::ARTICLE_TYPES, true) || str_ends_with($t, 'newsarticle')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $node */
    public static function hasType(array $node, string ...$types): bool
    {
        return array_intersect(self::types($node), array_map('strtolower', $types)) !== [];
    }

    /** First string found at the key (string, list of strings, or object with name, url or @id). */
    public static function str(mixed $value, string $prefer = 'name'): ?string
    {
        if (is_string($value)) {
            $v = Text::line(Text::decodeEntities($value));
            return $v !== '' ? $v : null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $v) {
                    $s = self::str($v, $prefer);
                    if ($s !== null) {
                        return $s;
                    }
                }
                return null;
            }
            foreach ([$prefer, 'name', 'url', '@id', '@value'] as $k) {
                if (isset($value[$k])) {
                    $s = self::str($value[$k], $prefer);
                    if ($s !== null) {
                        return $s;
                    }
                }
            }
        }
        return null;
    }

    private function addJsonLd(string $raw): void
    {
        $this->jsonLdBlocks++;
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }
        // Strip an HTML comment or CDATA wrapper some templates add.
        $raw = Text::replace('/^\s*(<!--|<!\[CDATA\[)|(-->|\]\]>)\s*$/', '', $raw);
        $data = json_decode($raw, true, 64, JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // Raw newlines and tabs inside strings are a common template fault.
            $data = json_decode(str_replace(["\r", "\n", "\t"], ' ', $raw), true, 64, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (!is_array($data)) {
            $this->jsonLdErrors++;
            return;
        }
        $this->collect($data, 0);
    }

    /** @param array<mixed> $data */
    private function collect(array $data, int $depth): void
    {
        if ($depth > 4) {
            return;
        }
        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    $this->collect($item, $depth + 1);
                }
            }
            return;
        }
        /** @var array<string, mixed> $data */
        if (isset($data['@type'])) {
            $this->nodes[] = $data;
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $this->collect($data['@graph'], $depth + 1);
        }
        foreach (['mainEntity', 'mainEntityOfPage'] as $k) {
            if (isset($data[$k]) && is_array($data[$k]) && isset($data[$k]['@type'])) {
                /** @var array<string, mixed> $child */
                $child = $data[$k];
                if (!self::hasType($child, 'WebPage')) {
                    $this->collect($child, $depth + 1);
                }
            } elseif (isset($data[$k]) && is_array($data[$k]) && array_is_list($data[$k])) {
                $this->collect($data[$k], $depth + 1);
            }
        }
    }

    private function chooseArticle(): void
    {
        $live = null;
        $withBody = null;
        $first = null;
        $mainVideo = false;
        $gallery = false;
        $index = false;
        foreach ($this->nodes as $node) {
            if (self::hasType($node, 'LiveBlogPosting')) {
                $live ??= $node;
            } elseif (self::isArticleNode($node)) {
                $first ??= $node;
                if ($withBody === null && is_string($node['articleBody'] ?? null) && trim($node['articleBody']) !== '') {
                    $withBody = $node;
                }
            }
            if (self::hasType($node, 'VideoObject')) {
                $mainVideo = true;
            }
            if (self::hasType($node, 'ImageGallery', 'MediaGallery')) {
                $gallery = true;
            }
            if (self::hasType($node, 'CollectionPage', 'ItemList')) {
                $index = true;
            }
            if (
                self::hasType($node, 'WebPage') && isset($node['mainEntity']) && is_array($node['mainEntity'])
                && (self::hasType($node['mainEntity'], 'ItemList') || array_is_list($node['mainEntity']))
            ) {
                $index = true;
            }
        }
        if ($live !== null) {
            $this->article = $live;
            $this->structuredType = 'liveblog';
        } elseif ($first !== null) {
            $this->article = $withBody ?? $first;
            $this->structuredType = 'article';
        } elseif ($mainVideo) {
            $this->structuredType = 'video';
        } elseif ($gallery) {
            $this->structuredType = 'gallery';
        } elseif ($index) {
            $this->structuredType = 'index';
        }
    }
}
