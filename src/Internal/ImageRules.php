<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

use IndianOpinion\ReadableExtract\ExtractorOptions;

/**
 * Image source selection and the placeholder and furniture tests
 * (PRD cases 31 to 35). Pure functions of URL strings and attributes;
 * nothing is downloaded.
 *
 * @internal
 */
final class ImageRules
{
    /** @var list<string> */
    private array $placeholders;
    /** @var list<string> */
    private array $chrome;
    /** @var list<string> */
    private array $lazy;
    private int $minW;
    private int $minH;

    public function __construct(ExtractorOptions $o)
    {
        $this->placeholders = $o->placeholderNames();
        $this->chrome = $o->chromeWords();
        $this->lazy = $o->lazyAttributes();
        $this->minW = $o->furnitureMinWidth();
        $this->minH = $o->furnitureMinHeight();
    }

    /**
     * Why a URL fails, or null when it passes 32, 34 and 35.
     */
    public function rejectReason(?string $url, ?int $declaredWidth = null, ?int $declaredHeight = null): ?string
    {
        if ($url === null || trim($url) === '') {
            return 'empty';
        }
        if (!Url::isHttp($url)) {
            return 'not_http';
        }
        if ($this->isUnderBar($declaredWidth, $declaredHeight)) {
            return 'declared_size';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = rawurldecode(basename($path));
        $lowerPath = strtolower(rawurldecode($path));
        if ($this->isPlaceholderName($name)) {
            return 'placeholder';
        }
        if (str_ends_with($lowerPath, '.svg') || str_ends_with($lowerPath, '.ico')) {
            return 'chrome_extension';
        }
        if ($this->hasChromePart($lowerPath)) {
            return 'chrome_name';
        }
        [$w, $h] = $this->sizeFromUrl($url);
        if ($this->isUnderBar($w, $h)) {
            return 'url_size';
        }
        return null;
    }

    public function isUnderBar(?int $w, ?int $h): bool
    {
        return ($w !== null && $w < $this->minW) || ($h !== null && $h < $this->minH);
    }

    /** Case 32: the file name, without extension, is made only of placeholder words, tiny sizes and short numbers. */
    public function isPlaceholderName(string $fileName): bool
    {
        $base = strtolower($fileName);
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > 0) {
            $base = substr($base, 0, $dot);
        }
        if ($base === '') {
            return true;
        }
        if (in_array($base, $this->placeholders, true)) {
            return true;
        }
        $parts = preg_split('/[-_.\s]+/', $base) ?: [];
        $sawWord = false;
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if (in_array($part, $this->placeholders, true) || preg_match('/^[0-9]{1,2}x[0-9]{1,2}$/', $part) === 1) {
                $sawWord = true;
                continue;
            }
            // Hyphenated placeholder words such as no-image.
            if (isset($parts[$i + 1]) && in_array($part . '-' . $parts[$i + 1], $this->placeholders, true)) {
                $sawWord = true;
                continue;
            }
            if ($i > 0 && in_array($parts[$i - 1] . '-' . $part, $this->placeholders, true)) {
                continue;
            }
            if (preg_match('/^[0-9]{1,3}$/', $part) === 1) {
                continue;
            }
            return false;
        }
        return $sawWord;
    }

    /** Case 35: a whole path segment, or a hyphen or underscore separated part of one, is a chrome word. */
    private function hasChromePart(string $lowerPath): bool
    {
        foreach (explode('/', $lowerPath) as $segment) {
            if ($segment === '') {
                continue;
            }
            $dot = strrpos($segment, '.');
            $stem = $dot !== false && $dot > 0 ? substr($segment, 0, $dot) : $segment;
            if (in_array($stem, $this->chrome, true)) {
                return true;
            }
            foreach (preg_split('/[-_.\s]+/', $stem) ?: [] as $part) {
                if (in_array($part, $this->chrome, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Case 34: size from explicit, labelled URL forms only. A bare number is
     * never read as a size.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function sizeFromUrl(string $url): array
    {
        $w = null;
        $h = null;
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            foreach (explode('&', $query) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $k = strtolower(rawurldecode($k));
                $v = strtolower(rawurldecode($v));
                $m = [];
                if ($k === 'size' && preg_match('/^(\d{1,5})\s*[x:*]\s*(\d{1,5})$/', $v, $m) === 1) {
                    $w = (int) $m[1];
                    $h = (int) $m[2];
                } elseif (($k === 'w' || $k === 'width') && ctype_digit($v) && strlen($v) <= 5) {
                    $w = (int) $v;
                } elseif (($k === 'h' || $k === 'height') && ctype_digit($v) && strlen($v) <= 5) {
                    $h = (int) $v;
                }
            }
        }
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (explode('/', $path) as $segment) {
            $m = [];
            if (preg_match('/(?:^|[-_,])width-(\d{1,5})(?:$|[-_,.])/', $segment, $m) === 1) {
                $w = (int) $m[1];
            }
            if (preg_match('/(?:^|[-_,])height-(\d{1,5})(?:$|[-_,.])/', $segment, $m) === 1) {
                $h = (int) $m[1];
            }
            if (preg_match('/(?:^|[-_,])square[-_](\d{1,5})(?:$|[-_,.])/', $segment, $m) === 1) {
                $w = (int) $m[1];
                $h = (int) $m[1];
            }
            if (preg_match('/^(\d{1,5})x(\d{1,5})$/', $segment, $m) === 1) {
                $w = (int) $m[1];
                $h = (int) $m[2];
            } elseif (preg_match('/[-_](\d{1,5})x(\d{1,5})\.[a-z0-9]{2,5}$/', $segment, $m) === 1) {
                $w = (int) $m[1];
                $h = (int) $m[2];
            }
        }
        return [$w, $h];
    }

    /**
     * Case 31: best real source of an img element, resolved against $base.
     * Returns null when the tag is furniture by its own size or nothing passes.
     *
     * @param list<string> $extraSrcsets srcset values from sibling picture/source elements
     * @return array{url: ?string, reason: string}
     */
    public function chooseSource(\DOMElement $img, string $base, array $extraSrcsets = []): array
    {
        if ($this->isUnderBar(Dom::attrInt($img, 'width'), Dom::attrInt($img, 'height'))) {
            return ['url' => null, 'reason' => 'declared_size'];
        }
        $last = 'no_source';
        foreach ($this->lazy as $attr) {
            if ($img->hasAttribute($attr)) {
                $u = Url::resolve($base, $img->getAttribute($attr));
                $r = $this->rejectReason($u);
                if ($r === null) {
                    return ['url' => $u, 'reason' => 'lazy:' . $attr];
                }
                $last = $r;
            }
        }
        $sets = [];
        foreach (['data-srcset', 'srcset'] as $attr) {
            if ($img->hasAttribute($attr)) {
                $sets[] = $img->getAttribute($attr);
            }
        }
        foreach (array_merge($sets, $extraSrcsets) as $set) {
            foreach ($this->rankSrcset($set) as $cand) {
                $u = Url::resolve($base, $cand);
                $r = $this->rejectReason($u);
                if ($r === null) {
                    return ['url' => $u, 'reason' => 'srcset'];
                }
                $last = $r;
            }
        }
        if ($img->hasAttribute('src')) {
            $u = Url::resolve($base, $img->getAttribute('src'));
            $r = $this->rejectReason($u);
            if ($r === null) {
                return ['url' => $u, 'reason' => 'src'];
            }
            $last = $r;
        }
        return ['url' => null, 'reason' => $last];
    }

    /**
     * Srcset candidates, best first: density descriptors (highest first)
     * rank above width descriptors (widest first); bare URLs last.
     *
     * @return list<string>
     */
    public function rankSrcset(string $srcset): array
    {
        $cands = [];
        $order = 0;
        foreach (preg_split('/,\s+|,(?=\s*(?:https?:)?\/)/', trim($srcset)) ?: [] as $item) {
            $item = trim($item, " \t\n\r,");
            if ($item === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $item) ?: [];
            $url = (string) array_shift($parts);
            $desc = strtolower((string) ($parts[0] ?? ''));
            $rank = 0.0;
            $m = [];
            if (preg_match('/^(\d+(?:\.\d+)?)x$/', $desc, $m) === 1) {
                $rank = 1000000.0 + (float) $m[1];
            } elseif (preg_match('/^(\d+)w$/', $desc, $m) === 1) {
                $rank = (float) $m[1];
            }
            $cands[] = [$rank, $order++, $url];
        }
        usort($cands, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        return array_map(static fn (array $c): string => $c[2], $cands);
    }
}
