<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * DOM helpers. Every walk is linear in the number of nodes it visits.
 *
 * @internal
 */
final class Dom
{
    private const INLINE = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'em', 'font', 'i', 'kbd', 'mark', 'q', 's', 'samp',
        'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr', 'label', 'ins', 'del',
    ];

    /**
     * Whitespace-collapsed text of a node. Block boundaries become spaces,
     * so "<h1>A</h1><p>B</p>" reads "A B", not "AB".
     */
    public static function text(\DOMNode $node): string
    {
        if (!$node instanceof \DOMElement || $node->getElementsByTagName('*')->length === 0) {
            return Text::line($node->textContent);
        }
        $buf = '';
        self::collect($node, $buf);
        return Text::line($buf);
    }

    private static function collect(\DOMNode $node, string &$buf): void
    {
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $buf .= $c->data;
            } elseif ($c instanceof \DOMElement) {
                $inline = in_array(strtolower($c->nodeName), self::INLINE, true);
                if (!$inline) {
                    $buf .= ' ';
                }
                self::collect($c, $buf);
                if (!$inline) {
                    $buf .= ' ';
                }
            }
        }
    }

    public static function name(\DOMNode $node): string
    {
        return strtolower($node->nodeName);
    }

    /** Lowercased class and id, underscores turned into hyphens, for hint matching. */
    public static function hintString(\DOMElement $el): string
    {
        $s = $el->getAttribute('class') . ' ' . $el->getAttribute('id');
        return str_replace('_', '-', strtolower(trim($s)));
    }

    /**
     * Number of hints that occur in the element's class or id.
     *
     * @param list<string> $hints
     */
    public static function hintCount(\DOMElement $el, array $hints): int
    {
        $s = self::hintString($el);
        if ($s === '') {
            return 0;
        }
        $n = 0;
        foreach ($hints as $h) {
            if (str_contains($s, $h)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return list<\DOMElement>
     */
    public static function byTag(\DOMNode $root, string $tag): array
    {
        $out = [];
        $list = $root instanceof \DOMDocument || $root instanceof \DOMElement
            ? $root->getElementsByTagName($tag)
            : null;
        if ($list === null) {
            return [];
        }
        foreach ($list as $el) {
            if ($el instanceof \DOMElement) {
                $out[] = $el;
            }
        }
        return $out;
    }

    /**
     * All elements under $root in document order.
     *
     * @return list<\DOMElement>
     */
    public static function all(\DOMNode $root): array
    {
        return self::byTag($root, '*');
    }

    public static function remove(\DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }

    /** Replace an element by its children. */
    public static function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    /** Share of the element's text that sits inside links, 0 to 1. */
    public static function linkDensity(\DOMElement $el, ?int $textLength = null): float
    {
        $links = $el->getElementsByTagName('a');
        if ($links->length === 0) {
            return 0.0;
        }
        $total = $textLength ?? self::length($el);
        if ($total === 0) {
            return 0.0;
        }
        $linked = 0;
        foreach ($links as $a) {
            $linked += self::length($a);
        }
        return min(1.0, $linked / $total);
    }

    /** Characters of text after whitespace collapse, without block spacing (fast). */
    public static function length(\DOMNode $node): int
    {
        return Text::proseLength($node->textContent);
    }

    public static function hasAncestor(\DOMNode $node, \DOMNode $ancestor): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /** True when the element is hidden by attribute or inline style. */
    public static function isHidden(\DOMElement $el): bool
    {
        if ($el->hasAttribute('hidden') || strtolower($el->getAttribute('aria-hidden')) === 'true') {
            return true;
        }
        $style = strtolower(str_replace(' ', '', $el->getAttribute('style')));
        return $style !== '' && (str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden'));
    }

    public static function attrInt(\DOMElement $el, string $name): ?int
    {
        if (!$el->hasAttribute($name)) {
            return null;
        }
        $v = trim(strtolower($el->getAttribute($name)));
        if (str_ends_with($v, 'px')) {
            $v = trim(substr($v, 0, -2));
        }
        return ctype_digit($v) && strlen($v) < 7 ? (int) $v : null;
    }
}
