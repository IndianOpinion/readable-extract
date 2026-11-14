<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

use IndianOpinion\ReadableExtract\Author;
use IndianOpinion\ReadableExtract\AuthorKind;
use IndianOpinion\ReadableExtract\AuthorRole;
use IndianOpinion\ReadableExtract\ExtractorOptions;

/**
 * Cases 43 to 45: byline splitting, kinds, roles and clean-up.
 *
 * @internal
 */
final class Authors
{
    private const PREFIXES = [
        'written and reported by', 'written by', 'reported by', 'posted by', 'story by', 'text by', 'words by',
        'compiled by', 'curated by', 'by', 'author:', 'authors:', 'reporter:', 'लेखक:', 'लेखक', 'रिपोर्ट:',
        'संवाददाता:', 'द्वारा',
    ];

    private const EDITOR_MARKERS = ['edited by', 'editor:', 'edited:', 'संपादन:', 'संपादक:', 'संपादित'];

    /** @var list<string> */
    public array $rejected = [];

    public function __construct(private readonly ExtractorOptions $o)
    {
    }

    /**
     * Split a byline into authors and editors.
     *
     * @return array{authors: list<Author>, editors: list<Author>}
     */
    public function fromByline(string $byline, ?string $url = null): array
    {
        $byline = Text::line($byline);
        // Cut off trailing date and update text.
        $byline = Text::split('/\s*[|\x{2022}]\s*|\s+(?:updated|published|last updated|posted on)\s*:?/iu', $byline)[0];
        $authorsPart = $byline;
        $editorsPart = '';
        $lower = Text::lower($byline);
        foreach (self::EDITOR_MARKERS as $marker) {
            $pos = mb_strpos($lower, $marker, 0, 'UTF-8');
            if ($pos !== false) {
                $authorsPart = mb_substr($byline, 0, $pos, 'UTF-8');
                $editorsPart = mb_substr($byline, $pos + mb_strlen($marker, 'UTF-8'), null, 'UTF-8');
                break;
            }
        }
        $authors = [];
        foreach ($this->split($authorsPart) as $name) {
            $authors[] = new Author($name, $url, $this->kind($name, null), AuthorRole::Author);
        }
        $editors = [];
        foreach ($this->split($editorsPart) as $name) {
            $editors[] = new Author($name, null, $this->kind($name, null), AuthorRole::Editor);
        }
        return ['authors' => $authors, 'editors' => $editors];
    }

    /**
     * Authors from a JSON-LD author value (string, Person, Organization or a list).
     *
     * @return list<Author>
     */
    public function fromJsonLd(mixed $value): array
    {
        $items = is_array($value) && array_is_list($value) ? $value : [$value];
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                foreach ($this->fromByline($item)['authors'] as $a) {
                    $out[] = $a;
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $name = Metadata::str($item['name'] ?? null);
            if ($name === null) {
                continue;
            }
            $url = Metadata::str($item['url'] ?? ($item['sameAs'] ?? null), 'url');
            $type = Metadata::hasType($item, 'Organization', 'NewsMediaOrganization', 'Corporation') ? 'org' : null;
            $names = $this->split($name);
            foreach ($names as $n) {
                $out[] = new Author($n, $url !== null && Url::isHttp($url) && count($names) === 1 ? $url : null, $this->kind($n, $type), AuthorRole::Author);
            }
        }
        return $out;
    }

    /**
     * Drop bad names (URL, email, over 80 characters) and merge duplicates.
     *
     * @param list<Author> $authors
     * @return list<Author>
     */
    public function clean(array $authors): array
    {
        $seen = [];
        $out = [];
        foreach ($authors as $a) {
            $name = Text::line($a->name);
            if (!$this->valid($name)) {
                $this->rejected[] = $name;
                continue;
            }
            $key = Text::lower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $name === $a->name ? $a : new Author($name, $a->url, $a->kind, $a->role);
        }
        return $out;
    }

    public function valid(string $name): bool
    {
        if ($name === '' || Text::length($name) > 80) {
            return false;
        }
        if (str_contains($name, '@') || preg_match('#^(https?:)?//|^www\.|://#i', $name) === 1) {
            return false;
        }
        return preg_match('/\p{L}/u', $name) === 1;
    }

    public function kind(string $name, ?string $type): AuthorKind
    {
        foreach ($this->o->agencyPatterns() as $p) {
            if (Text::containsPhrase($name, $p)) {
                return AuthorKind::Agency;
            }
        }
        return $type === 'org' ? AuthorKind::Organisation : AuthorKind::Person;
    }

    /** @return list<string> */
    private function split(string $text): array
    {
        $text = Text::line($text);
        if ($text === '') {
            return [];
        }
        $parts = Text::split('/\s*,\s*|\s*;\s*|\s+and\s+|\s*&\s*|\s+\x{0914}\x{0930}\s+/iu', $text);
        $out = [];
        foreach ($parts as $p) {
            $p = $this->stripPrefix(Text::line($p));
            $p = trim($p, " \t:-.");
            if ($p === '' || preg_match('/\d/u', Text::asciiDigits($p)) === 1) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    private function stripPrefix(string $p): string
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            $lower = Text::lower($p);
            foreach (self::PREFIXES as $prefix) {
                if ($lower === $prefix) {
                    return '';
                }
                if (str_starts_with($lower, $prefix . ' ') || (str_ends_with($prefix, ':') && str_starts_with($lower, $prefix))) {
                    $p = trim(mb_substr($p, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
                    $changed = true;
                    break;
                }
                // Hindi puts the credit word after the name: "Name द्वारा".
                if ($prefix === 'द्वारा' && str_ends_with($lower, ' ' . $prefix)) {
                    $p = trim(mb_substr($p, 0, mb_strlen($p, 'UTF-8') - mb_strlen($prefix, 'UTF-8'), 'UTF-8'));
                    $changed = true;
                    break;
                }
            }
        }
        return $p;
    }
}
