<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Parses UTF-8 HTML into a DOMDocument with libxml's HTML parser.
 *
 * The input is already UTF-8 (see Charset). A doctype and a charset meta
 * are put in front so libxml reads it as UTF-8 whatever the page itself
 * declares; the page's own doctype, and any remote DTD it names, is
 * ignored. No network: LIBXML_NONET, no DTD loading, no entity
 * substitution. Parser errors are collected through libxml's own error
 * handling and counted, never silenced with @.
 *
 * After parsing, the tree always has html > head + body, so callers can
 * rely on a body element even for fragments.
 *
 * @internal
 */
final class HtmlParser
{
    private const PREFIX = '<!DOCTYPE html><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    private const FLAGS = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_COMPACT;

    /**
     * @return array{0: \DOMDocument, 1: int} the document and the parser error count
     */
    public static function parse(string $html): array
    {
        $html = str_replace("\0", "\u{FFFD}", $html);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc->loadHTML(self::PREFIX . $html, self::FLAGS | (strlen($html) > 9000000 ? LIBXML_PARSEHUGE : 0));
            $errors = count(libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        self::normaliseTree($doc);
        return [$doc, $errors];
    }

    /**
     * Parse an HTML fragment (for example a JSON-LD articleBody) and return
     * the body element that holds it.
     */
    public static function fragment(string $html): \DOMElement
    {
        [$doc] = self::parse('<html><body>' . $html . '</body></html>');
        return self::body($doc);
    }

    /**
     * True when the opening html tag carries the AMP marker. libxml drops
     * the non-ASCII attribute name, so the source is checked, within its
     * first 8 KiB.
     */
    public static function sourceHasAmpMarker(string $html): bool
    {
        $prefix = substr($html, 0, 8192);
        $start = stripos($prefix, '<html');
        if ($start === false) {
            return false;
        }
        $end = strpos($prefix, '>', $start);
        $tag = substr($prefix, $start, $end === false ? null : $end - $start);
        return str_contains($tag, "\u{26A1}") || preg_match('/\samp(\s|=|$|\/)/i', $tag) === 1;
    }

    public static function body(\DOMDocument $doc): \DOMElement
    {
        $b = $doc->getElementsByTagName('body')->item(0);
        if ($b instanceof \DOMElement) {
            return $b;
        }
        self::normaliseTree($doc);
        $b = $doc->getElementsByTagName('body')->item(0);
        if (!$b instanceof \DOMElement) {
            throw new \LogicException('normaliseTree did not create a body');
        }
        return $b;
    }

    public static function head(\DOMDocument $doc): ?\DOMElement
    {
        $h = $doc->getElementsByTagName('head')->item(0);
        return $h instanceof \DOMElement ? $h : null;
    }

    private static function normaliseTree(\DOMDocument $doc): void
    {
        // Drop our prefix meta (the first top-level element).
        foreach (iterator_to_array($doc->childNodes) as $node) {
            if (
                $node instanceof \DOMElement && strtolower($node->nodeName) === 'meta'
                && strtolower($node->getAttribute('http-equiv')) === 'content-type'
                && str_contains($node->getAttribute('content'), 'charset=utf-8')
            ) {
                $doc->removeChild($node);
                break;
            }
        }
        $html = null;
        foreach ($doc->childNodes as $node) {
            if ($node instanceof \DOMElement && strtolower($node->nodeName) === 'html') {
                $html = $node;
                break;
            }
        }
        if ($html === null) {
            $html = $doc->createElement('html');
            $doc->appendChild($html);
        }
        $head = null;
        $body = null;
        foreach ($html->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $name = strtolower($child->nodeName);
                if ($name === 'head' && $head === null) {
                    $head = $child;
                } elseif ($name === 'body' && $body === null) {
                    $body = $child;
                }
            }
        }
        if ($head === null) {
            $head = $doc->createElement('head');
            $html->insertBefore($head, $html->firstChild);
        }
        if ($body === null) {
            $body = $doc->createElement('body');
            $html->appendChild($body);
        }
        // Move stray nodes (outside html, or loose inside html) into body.
        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node === $html || $node instanceof \DOMDocumentType) {
                continue;
            }
            $body->appendChild($node);
        }
        foreach (iterator_to_array($html->childNodes) as $node) {
            if ($node === $head || $node === $body) {
                continue;
            }
            $body->appendChild($node);
        }
    }
}
