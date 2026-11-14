<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Case 27 and 29: copies a body subtree into a fresh document through an
 * element and attribute allowlist, and renders plain text from the result.
 *
 * @internal
 */
final class Sanitiser
{
    public const ALLOWED = [
        'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption', 'img', 'a',
        'strong', 'em', 'b', 'i', 'br', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'pre', 'code',
    ];

    /** Elements dropped with everything inside them. */
    public const DROP = [
        'script', 'style', 'noscript', 'template', 'iframe', 'form', 'button', 'svg', 'canvas', 'nav', 'footer',
        'aside', 'object', 'embed', 'video', 'audio', 'input', 'select', 'textarea', 'link', 'meta', 'head',
        'title', 'math', 'map', 'source', 'track', 'dialog', 'frame', 'frameset', 'applet', 'param',
    ];

    private const BLOCK = [
        'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption', 'table',
        'thead', 'tbody', 'tr', 'pre',
    ];

    private const BREAK = 're-break';

    /**
     * @param list<\DOMNode> $nodes
     * @return array{html: string, text: string}
     */
    public function sanitise(array $nodes, string $base): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        foreach ($nodes as $node) {
            $this->copy($node, $root, $doc, $base);
            $root->appendChild($doc->createElement(self::BREAK));
        }
        $this->groupInline($root, $doc);
        $this->pruneEmpty($root);
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return ['html' => trim($html), 'text' => $this->render($root)];
    }

    private function copy(\DOMNode $src, \DOMNode $dst, \DOMDocument $doc, string $base): void
    {
        if ($src instanceof \DOMText) {
            if (!$src instanceof \DOMCdataSection) {
                $t = Text::strip($src->data);
                if ($t !== '') {
                    $dst->appendChild($doc->createTextNode($t));
                }
            }
            return;
        }
        if (!$src instanceof \DOMElement) {
            return;
        }
        $name = Dom::name($src);
        if (in_array($name, self::DROP, true)) {
            return;
        }
        if ($name === 'h1') {
            $name = 'h2';
        }
        if (!in_array($name, self::ALLOWED, true)) {
            $block = !in_array($name, ['span', 'font', 'small', 'big', 'u', 's', 'sup', 'sub', 'mark', 'abbr', 'cite',
                'time', 'q', 'label', 'ins', 'del', 'var', 'kbd', 'samp', 'bdi', 'bdo', 'wbr', 'data'], true);
            if ($block) {
                $dst->appendChild($doc->createElement(self::BREAK));
            }
            foreach ($src->childNodes as $child) {
                $this->copy($child, $dst, $doc, $base);
            }
            if ($block) {
                $dst->appendChild($doc->createElement(self::BREAK));
            }
            return;
        }
        if ($name === 'a') {
            $href = Url::resolve($base, $src->getAttribute('href'));
            if ($href === null || !Url::isHttp($href)) {
                foreach ($src->childNodes as $child) {
                    $this->copy($child, $dst, $doc, $base);
                }
                return;
            }
            $el = $doc->createElement('a');
            $el->setAttribute('href', $href);
        } elseif ($name === 'img') {
            $srcUrl = Url::resolve($base, $src->getAttribute('src'));
            if ($srcUrl === null || !Url::isHttp($srcUrl)) {
                return;
            }
            $el = $doc->createElement('img');
            $el->setAttribute('src', $srcUrl);
            if ($src->hasAttribute('alt')) {
                $el->setAttribute('alt', Text::line($src->getAttribute('alt')));
            }
            foreach (['width', 'height'] as $a) {
                $v = Dom::attrInt($src, $a);
                if ($v !== null) {
                    $el->setAttribute($a, (string) $v);
                }
            }
        } else {
            $el = $doc->createElement($name);
            if ($name === 'td' || $name === 'th') {
                foreach (['colspan', 'rowspan'] as $a) {
                    $v = Dom::attrInt($src, $a);
                    if ($v !== null && $v > 0 && $v < 1000) {
                        $el->setAttribute($a, (string) $v);
                    }
                }
            }
        }
        $dst->appendChild($el);
        foreach ($src->childNodes as $child) {
            $this->copy($child, $el, $doc, $base);
        }
        // Breaks are only meaningful at the top level.
        if ($name !== 'img') {
            foreach (iterator_to_array($el->childNodes, false) as $c) {
                if ($c instanceof \DOMElement && $c->nodeName === self::BREAK) {
                    if (in_array($name, ['li', 'td', 'th', 'blockquote', 'figcaption'], true)) {
                        $el->replaceChild($doc->createTextNode(' '), $c);
                    } else {
                        $el->removeChild($c);
                    }
                }
            }
        }
    }

    /** Wrap runs of inline nodes at the top level in <p>, splitting runs at break markers. */
    private function groupInline(\DOMElement $root, \DOMDocument $doc): void
    {
        /** @var list<list<\DOMNode>> $runs */
        $runs = [];
        $run = [];
        foreach (iterator_to_array($root->childNodes, false) as $child) {
            $isBreak = $child instanceof \DOMElement && $child->nodeName === self::BREAK;
            $isBlock = $child instanceof \DOMElement && in_array($child->nodeName, self::BLOCK, true);
            if ($isBreak || $isBlock) {
                if ($run !== []) {
                    $runs[] = $run;
                    $run = [];
                }
                if ($isBreak) {
                    $root->removeChild($child);
                }
                continue;
            }
            $run[] = $child;
        }
        if ($run !== []) {
            $runs[] = $run;
        }
        foreach ($runs as $nodes) {
            $hasContent = false;
            foreach ($nodes as $n) {
                if ($n instanceof \DOMText ? trim($n->data) !== '' : !($n instanceof \DOMElement && $n->nodeName === 'br')) {
                    $hasContent = true;
                    break;
                }
            }
            if (!$hasContent) {
                foreach ($nodes as $n) {
                    $root->removeChild($n);
                }
                continue;
            }
            $p = $doc->createElement('p');
            $root->insertBefore($p, $nodes[0]);
            foreach ($nodes as $n) {
                $p->appendChild($n);
            }
        }
    }

    private function pruneEmpty(\DOMElement $root): void
    {
        $els = Dom::all($root);
        for ($i = count($els) - 1; $i >= 0; $i--) {
            $el = $els[$i];
            $n = $el->nodeName;
            if ($n === 'img' || $n === 'br' || $n === 'td' || $n === 'th') {
                continue;
            }
            $tc = $el->textContent;
            if (preg_match('/[^\s\x{00A0}]/u', $tc) === 1 && trim(Text::strip($tc)) !== '') {
                continue;
            }
            if ($el->getElementsByTagName('img')->length === 0) {
                Dom::remove($el);
            }
        }
        // Trim leading and trailing br in paragraphs.
        foreach (Dom::byTag($root, 'p') as $p) {
            while ($p->firstChild instanceof \DOMElement && $p->firstChild->nodeName === 'br') {
                $p->removeChild($p->firstChild);
            }
            while ($p->lastChild instanceof \DOMElement && $p->lastChild->nodeName === 'br') {
                $p->removeChild($p->lastChild);
            }
        }
    }

    /** Plain text: blank line between blocks, list items on their own lines, br as a line break. */
    public function render(\DOMNode $root): string
    {
        $buf = '';
        $this->renderNode($root, $buf);
        $paras = [];
        foreach (explode("\x01", $buf) as $chunk) {
            $lines = [];
            foreach (explode("\x02", $chunk) as $line) {
                $line = Text::line($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            if ($lines !== []) {
                $paras[] = implode("\n", $lines);
            }
        }
        return implode("\n\n", $paras);
    }

    private function renderNode(\DOMNode $node, string &$buf): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $buf .= $child->data;
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $n = $child->nodeName;
            if ($n === 'img') {
                continue;
            }
            if ($n === 'br') {
                $buf .= "\x02";
                continue;
            }
            if ($n === 'li' || $n === 'tr') {
                $buf .= "\x02";
                $this->renderNode($child, $buf);
                $buf .= "\x02";
                continue;
            }
            if ($n === 'td' || $n === 'th') {
                $buf .= ' ';
                $this->renderNode($child, $buf);
                $buf .= ' ';
                continue;
            }
            if (in_array($n, self::BLOCK, true)) {
                $buf .= "\x01";
                $this->renderNode($child, $buf);
                $buf .= "\x01";
                continue;
            }
            $this->renderNode($child, $buf);
        }
    }
}
