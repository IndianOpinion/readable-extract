<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\ScoringWeights;
use IndianOpinion\ReadableExtract\Source;
use IndianOpinion\ReadableExtract\Trace;
use IndianOpinion\ReadableExtract\Warning;

/**
 * Body extraction: candidate gathering (21), the scoring walk (22), the
 * choice between visible and structured bodies (23 to 25), in-body
 * cleaning (26), images (31 to 36) and sanitising (27 to 29).
 *
 * @internal
 */
final class Body
{
    /** Removed from the working copy before scoring (case 13). */
    public const JUNK = [
        'script', 'style', 'noscript', 'template', 'iframe', 'form', 'button', 'svg', 'canvas', 'nav', 'footer',
        'aside', 'object', 'embed', 'input', 'select', 'textarea', 'dialog',
    ];

    private const PARAGRAPH_TAGS = ['p', 'pre', 'td', 'blockquote'];
    private const INLINE = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'em', 'font', 'i', 'img', 'kbd', 'mark', 'q',
        's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
    ];
    private const BIO_CONTAINER = ['author', 'profile', 'bio', 'byline', 'writer', 'columnist'];
    private const CLEAN_TOKENS = ['ad', 'ads', 'advertisement', 'adslot', 'ad-slot', 'ad-container', 'poll', 'polls',
        'subscribe', 'app-download', 'outbrain', 'taboola', 'tags', 'tag-list'];

    private ScoringWeights $w;
    private ImageRules $images;
    private Sanitiser $sanitiser;

    public function __construct(private readonly ExtractorOptions $o)
    {
        $this->w = $o->scoringWeights();
        $this->images = new ImageRules($o);
        $this->sanitiser = new Sanitiser();
    }

    /**
     * Copy the document and remove junk and hidden elements. Images inside
     * a noscript are recorded on their sibling img first (case 31).
     *
     * @param list<string> $paywallClasses classes marked not free by JSON-LD hasPart
     */
    public function workingCopy(\DOMDocument $doc, array $paywallClasses): \DOMDocument
    {
        $work = $doc->cloneNode(true);
        if (!$work instanceof \DOMDocument) {
            throw new \LogicException('cloneNode did not return a document');
        }
        foreach (Dom::byTag($work, 'noscript') as $ns) {
            $this->liftNoscriptImage($ns);
        }
        // XPath walks run in libxml, so only matching elements reach PHP.
        $xp = new \DOMXPath($work);
        $junk = $xp->query('//' . implode('|//', self::JUNK));
        $this->removeAll($junk);
        $hidden = [];
        $candidates = $xp->query('//body//*[@hidden or @aria-hidden or @style]');
        if ($candidates !== false) {
            foreach ($candidates as $el) {
                if ($el instanceof \DOMElement && Dom::isHidden($el)) {
                    $hidden[] = $el;
                }
            }
        }
        $this->removeAll($hidden);
        if ($paywallClasses !== []) {
            $paywalled = [];
            $withClass = $xp->query('//body//*[@class]');
            if ($withClass !== false) {
                foreach ($withClass as $el) {
                    if (!$el instanceof \DOMElement) {
                        continue;
                    }
                    $classes = preg_split('/\s+/', strtolower($el->getAttribute('class'))) ?: [];
                    if (array_intersect($classes, $paywallClasses) !== []) {
                        $paywalled[] = $el;
                    }
                }
            }
            $this->removeAll($paywalled);
        }
        return $work;
    }

    /**
     * @param iterable<\DOMNode|\DOMNameSpaceNode>|false $nodes
     */
    private function removeAll(iterable|false $nodes): void
    {
        if ($nodes === false) {
            return;
        }
        $list = [];
        foreach ($nodes as $n) {
            if ($n instanceof \DOMElement) {
                $list[] = $n;
            }
        }
        foreach ($list as $n) {
            Dom::remove($n);
        }
    }

    /**
     * Scoring walk (case 22). Returns the visible body nodes (top container
     * plus qualifying siblings) or an empty list.
     *
     * @return array{nodes: list<\DOMElement>, top: ?\DOMElement, score: float}
     */
    public function score(\DOMElement $body, ?Trace $trace): array
    {
        // Paragraphs inside negative containers (comments, rails) are left
        // out; when that leaves nothing, the walk runs again with them, so a
        // page whose only text is a biography is still seen (and refused).
        [$scores, $elements] = $this->gather($body, true);
        if (!$this->anyPositive($scores, $elements)) {
            [$scores, $elements] = $this->gather($body, false);
        }
        $top = null;
        $topScore = -INF;
        $final = [];
        foreach ($scores as $id => $raw) {
            $el = $elements[$id];
            $s = $raw * (1.0 - Dom::linkDensity($el));
            $final[$id] = $s;
            if ($s > $topScore) {
                $topScore = $s;
                $top = $el;
            }
        }
        if ($top === null || $topScore <= 0.0) {
            $trace?->candidate('body.container', Source::Visible, '', false, 'no container scored above zero');
            return ['nodes' => [], 'top' => null, 'score' => 0.0];
        }
        $nodes = [$top];
        $parent = $top->parentNode;
        if ($parent instanceof \DOMElement && $top !== $body && Dom::name($top) !== 'body') {
            $nodes = [];
            $threshold = max($this->w->get('siblingMin'), $topScore * $this->w->get('siblingRatio'));
            foreach ($parent->childNodes as $sib) {
                if (!$sib instanceof \DOMElement) {
                    continue;
                }
                if ($sib === $top) {
                    $nodes[] = $sib;
                    continue;
                }
                if ($this->negative($sib) > $this->positive($sib)) {
                    continue;
                }
                $s = $final[spl_object_id($sib)] ?? 0.0;
                if ($s >= $threshold) {
                    $nodes[] = $sib;
                    $trace?->candidate('body.sibling', Source::Visible, Dom::text($sib), true, 'sibling score', $s);
                    continue;
                }
                if (Dom::name($sib) === 'p') {
                    $t = Dom::text($sib);
                    $len = Text::length($t);
                    $ld = Dom::linkDensity($sib, $len);
                    if (
                        ($len >= $this->w->get('siblingParagraphMinChars') && $ld < $this->w->get('siblingParagraphMaxLinkDensity'))
                        || ($len > 0 && $ld === 0.0 && preg_match('/[.\x{0964}\x{0965}?!\x{06D4}]$/u', $t) === 1)
                    ) {
                        $nodes[] = $sib;
                    }
                }
            }
        }
        if ($trace !== null) {
            $ranked = [];
            foreach ($final as $id => $s) {
                $ranked[] = [$s, $elements[$id]];
            }
            usort($ranked, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
            foreach (array_slice($ranked, 0, 5) as $i => [$s, $el]) {
                $label = Dom::name($el) . ($el->getAttribute('class') !== '' ? '.' . $el->getAttribute('class') : '');
                $trace->candidate('body.container', Source::Visible, $label . ': ' . Dom::text($el), $i === 0, $i === 0 ? 'top score' : 'lower score', $s);
            }
        }
        return ['nodes' => $nodes, 'top' => $top, 'score' => $topScore];
    }

    /**
     * Run the whole body step.
     */
    public function extract(
        \DOMDocument $work,
        Metadata $meta,
        string $base,
        ?string $title,
        ?Trace $trace,
    ): BodyResult {
        $res = new BodyResult();
        $min = $this->o->proseMinimum();
        $body = HtmlParser::body($work);

        // a. Visible body.
        $scored = $this->score($body, $trace);
        $visibleNodes = $scored['nodes'];
        $res->container = $scored['top'];
        $visibleText = '';
        foreach ($visibleNodes as $n) {
            $visibleText .= ' ' . Dom::text($n);
        }
        $visibleLen = Text::proseLength($visibleText);
        $res->visibleLength = $visibleLen;
        $res->visibleLinkDensity = $this->linkDensityOf($visibleNodes, $visibleLen);
        $visibleIsBio = $visibleNodes !== [] && $this->isBiography($visibleNodes, $visibleText, $visibleLen);

        // b. Microdata articleBody (in the working copy, so junk is gone).
        $micro = null;
        foreach (Dom::all($body) as $el) {
            if (in_array('articlebody', preg_split('/\s+/', strtolower($el->getAttribute('itemprop'))) ?: [], true)) {
                $micro = $el;
                break;
            }
        }
        $microLen = $micro !== null ? Text::proseLength($micro->textContent) : 0;

        // c. JSON-LD articleBody.
        $ldBody = $meta->article !== null && is_string($meta->article['articleBody'] ?? null)
            ? trim($meta->article['articleBody']) : '';
        $ldNodes = [];
        if ($ldBody !== '') {
            $ldNodes = $this->structuredNodes($ldBody);
        }
        $ldLen = 0;
        foreach ($ldNodes as $n) {
            $ldLen += Text::proseLength($n->textContent) + 1;
        }
        $ldLen = max(0, $ldLen - 1);

        $trace?->candidate('body', Source::Visible, $visibleText, false, 'length ' . $visibleLen . ($visibleIsBio ? ', biography' : ''), (float) $visibleLen);
        $trace?->candidate('body', Source::Microdata, $micro !== null ? $micro->textContent : '', false, 'length ' . $microLen, (float) $microLen);
        $trace?->candidate('body', Source::JsonLd, $ldBody, false, 'length ' . $ldLen, (float) $ldLen);

        $structured = null;
        $structuredSource = Source::None;
        $structuredLen = 0;
        if ($micro !== null && $microLen >= $min) {
            [$structured, $structuredSource, $structuredLen] = [[$micro], Source::Microdata, $microLen];
        } elseif ($ldLen >= $min) {
            [$structured, $structuredSource, $structuredLen] = [$ldNodes, Source::JsonLd, $ldLen];
        } elseif ($microLen >= $ldLen && $micro !== null) {
            [$structured, $structuredSource, $structuredLen] = [[$micro], Source::Microdata, $microLen];
        } elseif ($ldNodes !== []) {
            [$structured, $structuredSource, $structuredLen] = [$ldNodes, Source::JsonLd, $ldLen];
        }
        $res->structuredLength = $structuredLen;
        $res->structuredMeetsMinimum = $structuredLen >= $min;

        $chosen = [];
        $chosenSource = Source::None;
        $reason = 'no candidate';
        if ($visibleLen >= $min && !$visibleIsBio) {
            $chosen = $visibleNodes;
            $chosenSource = Source::Visible;
            $res->confidence = $this->w->get('confVisible');
            $reason = 'visible body meets the prose minimum';
            if ($structured !== null && $structuredLen >= $min) {
                $structuredText = '';
                foreach ($structured as $n) {
                    $structuredText .= ' ' . $n->textContent;
                }
                $overlap = $this->overlap($visibleText, $structuredText);
                $trace?->note('body.overlap', round($overlap, 3));
                if ($overlap < $this->o->bodyAgreement()) {
                    $res->warnings[] = Warning::BodyDisagreement;
                    $reason .= '; structured body disagrees';
                } else {
                    $res->confidence = $this->w->get('confVisibleAgreed');
                    $reason .= '; structured body agrees';
                }
            }
        } elseif ($structured !== null && $structuredLen >= $min) {
            $chosen = $structured;
            $chosenSource = $structuredSource;
            $res->confidence = $this->w->get('confStructured');
            $res->warnings[] = Warning::VisibleBodyShort;
            $reason = 'visible body short; structured body used';
        } else {
            // Nothing meets the minimum: the longest candidate that is not a biography.
            $visibleOk = $visibleNodes !== [] && !$visibleIsBio;
            if ($structured !== null && (!$visibleOk || $structuredLen > $visibleLen)) {
                $chosen = $structured;
                $chosenSource = $structuredSource;
                $reason = 'short structured body';
            } elseif ($visibleOk) {
                $chosen = $visibleNodes;
                $chosenSource = Source::Visible;
                $reason = 'short visible body';
            } elseif ($visibleIsBio) {
                $res->warnings[] = Warning::BiographyOnly;
                $reason = 'only a biography was found';
            }
            $len = $chosenSource === Source::Visible ? $visibleLen : $structuredLen;
            $res->confidence = $chosen === [] ? 0.0 : $this->w->get('confShortMax') * min(1.0, $len / max(1, $min));
        }
        $trace?->candidate('body', $chosenSource, '', $chosen !== [], $reason);

        if ($chosen === []) {
            return $res;
        }
        $res->source = $chosenSource;
        [$res->html, $res->text] = $this->clean($chosen, $base, $title);
        if ($res->text === '') {
            $res->confidence = 0.0;
            $res->source = Source::None;
        }
        return $res;
    }

    /**
     * Clean and sanitise chosen nodes (cases 26 to 29, 31 to 36).
     *
     * @param list<\DOMElement> $nodes
     * @return array{0: string, 1: string}
     */
    public function clean(array $nodes, string $base, ?string $title): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        foreach ($nodes as $n) {
            $root->appendChild($doc->importNode($n, true));
        }
        $this->removeBoilerplate($root);
        $this->embedsToLinks($root, $base);
        $this->removeRepeatedHead($root, $title);
        (new ImagePass($this->images, $this->o->lazyAttributes()))->apply($root, $base);
        $out = $this->sanitiser->sanitise(iterator_to_array($root->childNodes, false), $base);
        return [$out['html'], $out['text']];
    }

    /**
     * Liveblog updates as body (case 19).
     *
     * @param list<array{time: ?string, headline: ?string, body: string}> $updates
     * @return array{0: string, 1: string}
     */
    public function liveblog(array $updates, string $base): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        $first = true;
        foreach ($updates as $u) {
            if (!$first) {
                $sep = $doc->createElement('p');
                $sep->appendChild($doc->createTextNode('* * *'));
                $root->appendChild($sep);
            }
            $first = false;
            if ($u['time'] !== null) {
                $p = $doc->createElement('p');
                $em = $doc->createElement('em');
                $em->appendChild($doc->createTextNode($u['time']));
                $p->appendChild($em);
                $root->appendChild($p);
            }
            if ($u['headline'] !== null) {
                $h = $doc->createElement('h3');
                $h->appendChild($doc->createTextNode($u['headline']));
                $root->appendChild($h);
            }
            foreach ($this->structuredNodes($u['body']) as $n) {
                foreach (iterator_to_array($n->childNodes, false) as $c) {
                    $root->appendChild($doc->importNode($c, true));
                }
            }
        }
        $out = $this->sanitiser->sanitise(iterator_to_array($root->childNodes, false), $base);
        return [$out['html'], $out['text']];
    }

    /**
     * Turn a structured body string into DOM nodes: HTML is parsed as a
     * fragment, plain text becomes paragraphs.
     *
     * @return list<\DOMElement>
     */
    public function structuredNodes(string $body): array
    {
        $body = Text::decodeEntities($body);
        if (preg_match('/<(p|div|br|h[1-6]|ul|ol|blockquote|figure)\b/i', $body) === 1) {
            return [HtmlParser::fragment($body)];
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        $paras = Text::split('/\n\s*\n|\n/u', str_replace(["\r\n", "\r"], "\n", $body));
        foreach ($paras as $p) {
            $p = Text::line($p);
            if ($p !== '') {
                $el = $doc->createElement('p');
                $el->appendChild($doc->createTextNode($p));
                $root->appendChild($el);
            }
        }
        return [$root];
    }

    /**
     * Overlap of two texts: shared distinct word pairs (bigrams) over the
     * smaller set. Pairs, not single words, so common function words do
     * not make two unrelated texts look alike.
     */
    public function overlap(string $a, string $b): float
    {
        $pa = $this->pairs($a);
        $pb = $this->pairs($b);
        if ($pa === [] || $pb === []) {
            return 0.0;
        }
        $shared = count(array_intersect_key($pa, $pb));
        return $shared / min(count($pa), count($pb));
    }

    /** @return array<string, true> */
    private function pairs(string $text): array
    {
        $t = Text::tokens($text, $this->o->useIntl());
        $out = [];
        $n = count($t);
        if ($n === 1) {
            $out[$t[0]] = true;
        }
        for ($i = 1; $i < $n; $i++) {
            $out[$t[$i - 1] . ' ' . $t[$i]] = true;
        }
        return $out;
    }

    private function paragraphScore(string $text, int $len): float
    {
        $commas = 0;
        foreach ([',', "\u{060C}", "\u{3001}", "\u{FF0C}"] as $c) {
            $commas += substr_count($text, $c);
        }
        $marks = 0;
        foreach (['.', '?', '!', "\u{0964}", "\u{0965}", "\u{06D4}", "\u{3002}"] as $c) {
            $marks += substr_count($text, $c);
        }
        return $this->w->get('paragraphBase')
            + $commas * $this->w->get('commaWeight')
            + $marks * $this->w->get('sentenceWeight')
            + min($this->w->get('lengthCap'), floor($len / max(1.0, $this->w->get('lengthStep'))));
    }

    /**
     * @param array<int, float>       $scores
     * @param array<int, \DOMElement> $elements
     */
    private function addScore(array &$scores, array &$elements, \DOMElement $el, float $points): void
    {
        $id = spl_object_id($el);
        if (!isset($scores[$id])) {
            $scores[$id] = $this->initialScore($el);
            $elements[$id] = $el;
        }
        $scores[$id] += $points;
    }

    private function initialScore(\DOMElement $el): float
    {
        $tag = Dom::name($el);
        $s = match (true) {
            $tag === 'article' => $this->w->get('tagArticle'),
            in_array($tag, ['div', 'section', 'main'], true) => $this->w->get('tagBlock'),
            in_array($tag, ['pre', 'td', 'blockquote'], true) => $this->w->get('tagMinor'),
            in_array($tag, ['ul', 'ol', 'li', 'dl', 'dd', 'dt'], true) => $this->w->get('tagList'),
            in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'th'], true) => $this->w->get('tagHeading'),
            default => 0.0,
        };
        if (in_array('articlebody', preg_split('/\s+/', strtolower($el->getAttribute('itemprop'))) ?: [], true)) {
            $s += $this->w->get('hintWeight');
        }
        return $s + ($this->positive($el) - $this->negative($el)) * $this->w->get('hintWeight');
    }

    /**
     * Paragraph scores credited to parents and grandparents (case 22).
     *
     * @return array{0: array<int, float>, 1: array<int, \DOMElement>}
     */
    private function gather(\DOMElement $body, bool $skipNegative): array
    {
        /** @var array<int, float> $scores */
        $scores = [];
        /** @var array<int, \DOMElement> $elements */
        $elements = [];
        /** @var array<int, bool> $excluded */
        $excluded = [];
        foreach (Dom::all($body) as $el) {
            if (!$this->isParagraph($el)) {
                continue;
            }
            if ($skipNegative && $this->insideNegativeContainer($el, $body, $excluded)) {
                continue;
            }
            $text = Dom::text($el);
            $len = Text::length($text);
            if ($len < $this->w->get('paragraphMinChars')) {
                continue;
            }
            $points = $this->paragraphScore($text, $len);
            $parent = $el->parentNode;
            if (!$parent instanceof \DOMElement) {
                continue;
            }
            $this->addScore($scores, $elements, $parent, $points);
            $gp = $parent->parentNode;
            if ($gp instanceof \DOMElement && $parent !== $body) {
                $this->addScore($scores, $elements, $gp, $points * $this->w->get('grandparentShare'));
            }
        }
        return [$scores, $elements];
    }

    /**
     * @param array<int, float>       $scores
     * @param array<int, \DOMElement> $elements
     */
    private function anyPositive(array $scores, array $elements): bool
    {
        foreach ($scores as $id => $raw) {
            if ($raw * (1.0 - Dom::linkDensity($elements[$id])) > 0.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when an ancestor below body, and below the nearest article, main
     * or articleBody element, is a negative container (comments, related,
     * sidebar...): more negative than positive hints. Paragraphs there score nothing,
     * so a long comment thread or rail cannot outscore a short article.
     * Results are memoised per ancestor, so the walk stays linear.
     *
     * @param array<int, bool> $memo
     */
    private function insideNegativeContainer(\DOMElement $el, \DOMElement $body, array &$memo): bool
    {
        $chain = [];
        $result = false;
        for ($n = $el->parentNode; $n instanceof \DOMElement && $n !== $body; $n = $n->parentNode) {
            $id = spl_object_id($n);
            if (isset($memo[$id])) {
                $result = $memo[$id];
                break;
            }
            $chain[] = $id;
            if ($this->isShield($n)) {
                break;
            }
            if ($this->negative($n) > $this->positive($n)) {
                $result = true;
                break;
            }
        }
        foreach ($chain as $id) {
            $memo[$id] = $result;
        }
        return $result;
    }

    /** article, main and articleBody elements shield what they hold from wrappers further up. */
    private function isShield(\DOMElement $el): bool
    {
        return in_array(Dom::name($el), ['article', 'main'], true)
            || in_array('articlebody', preg_split('/\s+/', strtolower($el->getAttribute('itemprop'))) ?: [], true);
    }

    private function positive(\DOMElement $el): int
    {
        return Dom::hintCount($el, $this->o->positiveHints());
    }

    private function negative(\DOMElement $el): int
    {
        return Dom::hintCount($el, $this->o->negativeHints());
    }

    private function isParagraph(\DOMElement $el): bool
    {
        $tag = Dom::name($el);
        if (in_array($tag, self::PARAGRAPH_TAGS, true)) {
            return true;
        }
        if (!in_array($tag, ['div', 'section', 'article', 'span'], true)) {
            return false;
        }
        // A div used as a paragraph: only text and inline children, with some direct text.
        $direct = '';
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                if (!in_array(Dom::name($c), self::INLINE, true)) {
                    return false;
                }
            } elseif ($c instanceof \DOMText) {
                $direct .= $c->data;
            }
        }
        return trim($direct) !== '' && $tag !== 'span';
    }

    /**
     * Case 24: a single short paragraph that sits in an author or profile
     * container, or reads "Name is a ... / writes on / covers".
     *
     * @param list<\DOMElement> $nodes
     */
    private function isBiography(array $nodes, string $text, int $len): bool
    {
        if ($len >= $this->o->proseMinimum()) {
            return false;
        }
        $paras = 0;
        $inBio = true;
        foreach ($nodes as $n) {
            $ps = [];
            foreach (Dom::all($n) as $el) {
                if ($this->isParagraph($el) && Text::length(Dom::text($el)) >= 25) {
                    $ps[] = $el;
                }
            }
            if ($ps === [] && $this->isParagraph($n)) {
                $ps = [$n];
            }
            $paras += count($ps);
            foreach ($ps as $p) {
                if (!$this->inBioContainer($p)) {
                    $inBio = false;
                }
            }
        }
        if ($paras > 1) {
            return false;
        }
        if ($paras === 1 && $inBio) {
            return true;
        }
        $text = Text::line($text);
        $head = mb_substr($text, 0, 80, 'UTF-8');
        foreach ($this->o->biographyPatterns() as $pat) {
            $pos = mb_stripos($head, ' ' . $pat . ' ', 0, 'UTF-8');
            if ($pos !== false && $pos > 0) {
                $words = count(preg_split('/\s+/u', trim(mb_substr($head, 0, $pos, 'UTF-8'))) ?: []);
                if ($words >= 1 && $words <= 5) {
                    return true;
                }
            }
        }
        return false;
    }

    private function inBioContainer(\DOMElement $el): bool
    {
        for ($p = $el; $p instanceof \DOMElement; $p = $p->parentNode) {
            $h = Dom::hintString($p);
            if ($h === '') {
                continue;
            }
            foreach (self::BIO_CONTAINER as $word) {
                if (str_contains($h, $word)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<\DOMElement> $nodes */
    private function linkDensityOf(array $nodes, int $len): float
    {
        if ($len === 0) {
            return 0.0;
        }
        $linked = 0;
        foreach ($nodes as $n) {
            foreach (Dom::byTag($n, 'a') as $a) {
                $linked += Text::length(Dom::text($a));
            }
            if (Dom::name($n) === 'a') {
                $linked += Text::length(Dom::text($n));
            }
        }
        return min(1.0, $linked / $len);
    }

    private function liftNoscriptImage(\DOMElement $ns): void
    {
        $src = null;
        $found = Dom::byTag($ns, 'img');
        if ($found !== []) {
            $src = $found[0]->getAttribute('src');
        } elseif (stripos($ns->textContent, '<img') !== false) {
            $frag = HtmlParser::fragment($ns->textContent);
            $imgs = Dom::byTag($frag, 'img');
            if ($imgs !== []) {
                $src = $imgs[0]->getAttribute('src');
            }
        }
        if ($src === null || trim($src) === '') {
            return;
        }
        $target = null;
        for ($s = $ns->previousSibling; $s !== null; $s = $s->previousSibling) {
            if ($s instanceof \DOMElement) {
                if (Dom::name($s) === 'img') {
                    $target = $s;
                } elseif (($inner = Dom::byTag($s, 'img')) !== []) {
                    $target = $inner[count($inner) - 1];
                }
                break;
            }
        }
        if ($target === null && $ns->parentNode instanceof \DOMElement) {
            foreach (Dom::byTag($ns->parentNode, 'img') as $img) {
                if (!Dom::hasAncestor($img, $ns)) {
                    $target = $img;
                    break;
                }
            }
        }
        if ($target !== null) {
            $target->setAttribute(ImagePass::NOSCRIPT_ATTR, $src);
            return;
        }
        $doc = $ns->ownerDocument;
        if ($doc !== null && $ns->parentNode !== null) {
            $img = $doc->createElement('img');
            $img->setAttribute('src', $src);
            $ns->parentNode->insertBefore($img, $ns);
        }
    }

    private function removeBoilerplate(\DOMElement $root): void
    {
        $min = $this->o->proseMinimum();
        foreach (Dom::all($root) as $el) {
            if (!Dom::hasAncestor($el, $root)) {
                continue;
            }
            $tag = Dom::name($el);
            if ($tag === 'img' || $tag === 'br') {
                continue;
            }
            // Negative class or id hints: related lists, share bars, promos.
            if ($this->negative($el) > $this->positive($el)) {
                $len = Dom::length($el);
                if ($len < $min || Dom::linkDensity($el, $len) > 0.5) {
                    Dom::remove($el);
                    continue;
                }
            }
            $hint = Dom::hintString($el);
            if ($hint !== '') {
                foreach (preg_split('/\s+/', $hint) ?: [] as $t) {
                    if ($t !== '' && $this->isCleanToken($t)) {
                        Dom::remove($el);
                        continue 2;
                    }
                }
            }
            // "Also read" blocks, newsletter and app prompts: short leaf blocks
            // only, so a container that also holds the article is never removed.
            $raw = $el->textContent;
            if (strlen($raw) > 1500 || $this->hasBlockChild($el)) {
                if (($tag === 'ul' || $tag === 'ol') && $el->getElementsByTagName('a')->length >= 2 && Dom::linkDensity($el) > 0.5) {
                    Dom::remove($el);
                }
                continue;
            }
            $text = Text::line($raw);
            $len = Text::length($text);
            if ($len === 0 || $len >= 300) {
                continue;
            }
            $lower = Text::lower($text);
            foreach ($this->o->alsoReadPhrases() as $phrase) {
                if (str_starts_with($lower, $phrase)) {
                    $next = $el->nextSibling;
                    while ($next instanceof \DOMText && trim($next->data) === '') {
                        $next = $next->nextSibling;
                    }
                    if (
                        preg_match('/^h[1-6]$|^strong$|^b$/', $tag) === 1 && $next instanceof \DOMElement
                        && in_array(Dom::name($next), ['ul', 'ol'], true)
                    ) {
                        Dom::remove($next);
                    }
                    Dom::remove($el);
                    continue 2;
                }
            }
            if ($len < 200) {
                foreach ($this->o->boilerplatePhrases() as $phrase) {
                    if (str_contains($lower, $phrase)) {
                        Dom::remove($el);
                        continue 2;
                    }
                }
            }
            // Lists that are mostly links (related stories).
            if (($tag === 'ul' || $tag === 'ol') && $el->getElementsByTagName('a')->length >= 2 && Dom::linkDensity($el, $len) > 0.5) {
                Dom::remove($el);
            }
        }
    }

    private function hasBlockChild(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if (
                $c instanceof \DOMElement && in_array(Dom::name($c), [
                'p', 'div', 'section', 'article', 'ul', 'ol', 'table', 'blockquote', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'main',
                ], true)
            ) {
                return true;
            }
        }
        return false;
    }

    private function isCleanToken(string $token): bool
    {
        foreach (self::CLEAN_TOKENS as $c) {
            if ($token === $c || str_starts_with($token, $c . '-')) {
                return true;
            }
        }
        return false;
    }

    private function embedsToLinks(\DOMElement $root, string $base): void
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return;
        }
        foreach (Dom::byTag($root, 'blockquote') as $bq) {
            $hint = Dom::hintString($bq);
            if (!preg_match('/tweet|twitter|instagram|embed|social|facebook|threads|bluesky|mastodon|post/', $hint)) {
                continue;
            }
            $href = null;
            foreach (Dom::byTag($bq, 'a') as $a) {
                $u = Url::resolve($base, $a->getAttribute('href'));
                if ($u !== null && Url::isHttp($u)) {
                    $href = $u;
                }
            }
            if ($href === null && $bq->hasAttribute('data-instgrm-permalink')) {
                $href = Url::resolve($base, $bq->getAttribute('data-instgrm-permalink'));
            }
            if ($href === null || !Url::isHttp($href)) {
                Dom::remove($bq);
                continue;
            }
            $p = $doc->createElement('p');
            $a = $doc->createElement('a');
            $a->setAttribute('href', $href);
            $a->appendChild($doc->createTextNode($href));
            $p->appendChild($a);
            $bq->parentNode?->replaceChild($p, $bq);
        }
    }

    private function removeRepeatedHead(\DOMElement $root, ?string $title): void
    {
        $key = static fn (string $s): string => Text::replace('/[^\p{L}\p{N}\p{M}]+/u', '', Text::lower($s));
        $titleKey = $title !== null ? $key($title) : '';
        $checked = 0;
        foreach (Dom::all($root) as $el) {
            if ($checked >= 6) {
                break;
            }
            if (!Dom::hasAncestor($el, $root)) {
                continue;
            }
            $tag = Dom::name($el);
            if (!in_array($tag, ['h1', 'h2', 'h3', 'p', 'div', 'span', 'time'], true)) {
                continue;
            }
            $text = Dom::text($el);
            if ($text === '') {
                continue;
            }
            $checked++;
            if ($titleKey !== '' && $key($text) === $titleKey) {
                Dom::remove($el);
                continue;
            }
            $hint = Dom::hintString($el);
            $isByline = str_contains($hint, 'byline') || (str_contains($hint, 'author') && Text::length($text) < 120);
            if ($isByline || (Text::length($text) < 120 && preg_match('/^(by|written by|reported by|posted by)\s/iu', $text) === 1)) {
                Dom::remove($el);
                continue;
            }
            if ($tag === 'p' && Text::length($text) >= 120) {
                break;
            }
        }
    }
}
