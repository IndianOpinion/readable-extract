<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

use IndianOpinion\ReadableExtract\Internal\Authors;
use IndianOpinion\ReadableExtract\Internal\Body;
use IndianOpinion\ReadableExtract\Internal\BodyResult;
use IndianOpinion\ReadableExtract\Internal\Charset;
use IndianOpinion\ReadableExtract\Internal\Dates;
use IndianOpinion\ReadableExtract\Internal\Dom;
use IndianOpinion\ReadableExtract\Internal\HtmlParser;
use IndianOpinion\ReadableExtract\Internal\ImageRules;
use IndianOpinion\ReadableExtract\Internal\Metadata;
use IndianOpinion\ReadableExtract\Internal\Scripts;
use IndianOpinion\ReadableExtract\Internal\Text;
use IndianOpinion\ReadableExtract\Internal\TitleRules;
use IndianOpinion\ReadableExtract\Internal\Url;
use IndianOpinion\ReadableExtract\Internal\WordCounter;

/**
 * Extracts an Article from a page's HTML. Offline: extraction never
 * touches the network. Odd HTML never throws; it returns warnings. Only an
 * invalid URL throws (InvalidUrlException).
 */
final class Extractor
{
    private ExtractorOptions $o;
    private ScoringWeights $w;

    public function __construct(?ExtractorOptions $options = null)
    {
        $this->o = $options ?? new ExtractorOptions();
        $this->w = $this->o->scoringWeights();
    }

    public function options(): ExtractorOptions
    {
        return $this->o;
    }

    public function extract(string $html, string $url, ?PageContext $context = null): Article
    {
        Url::assertInput($url);
        $o = $this->o;
        $trace = $o->trace() ? new Trace() : null;
        $warnings = [];
        $intl = $o->useIntl() && class_exists(\Normalizer::class);
        if (!$intl) {
            $warnings[] = Warning::NoNormalizer;
        }
        if ($context !== null && $context->bodyTruncated) {
            $warnings[] = Warning::BodyTruncated;
        }

        if ($this->isWrapper($url)) {
            $warnings[] = Warning::WrapperUrlNotResolved;
            $trace?->note('wrapper', true);
            return $this->bare(PageType::Wrapper, $warnings, $trace);
        }
        if (trim($html) === '') {
            $warnings[] = Warning::EmptyInput;
            return $this->bare(PageType::Empty, $warnings, $trace);
        }

        // Encoding (cases 6, 7), input cap (3), normalisation (8).
        $decoded = Charset::toUtf8($html, $context?->contentTypeValue());
        array_push($warnings, ...$decoded['warnings']);
        $trace?->note('charset', $decoded['charset']);
        $trace?->note('charset.source', $decoded['source']);
        if ($decoded['invalid'] > 0) {
            $trace?->note('invalid_sequences', $decoded['invalid']);
        }
        $text = $decoded['text'];
        unset($html, $decoded);
        if (strlen($text) > $o->inputCap()) {
            $text = mb_strcut($text, 0, $o->inputCap(), 'UTF-8');
            $warnings[] = Warning::InputTruncated;
        }
        if (trim(Text::strip($text)) === '') {
            $warnings[] = Warning::EmptyInput;
            return $this->bare(PageType::Empty, $warnings, $trace);
        }
        $text = Text::nfc($text, $intl);

        // Parse (10 to 12) and read structured data before anything is removed.
        $ampMarker = HtmlParser::sourceHasAmpMarker($text);
        [$doc, $parseErrors] = HtmlParser::parse($text);
        unset($text);
        $trace?->note('parser_errors', $parseErrors);
        $meta = Metadata::read($doc);
        $meta->amp = $meta->amp || $ampMarker;
        $trace?->note('json_ld_blocks', $meta->jsonLdBlocks);
        if ($meta->jsonLdErrors > 0) {
            $trace?->note('json_ld_errors', $meta->jsonLdErrors);
        }
        $article = $meta->article;
        $base = $url;
        if ($meta->baseHref !== null) {
            $b = Url::resolve($url, $meta->baseHref);
            if ($b !== null && Url::isHttp($b)) {
                $base = $b;
            }
        }
        $inputHost = (string) Url::host($url);

        $siteName = $this->siteName($meta, $inputHost);
        $accessible = $this->accessibleForFree($article);
        $paywallClasses = $this->paywallClasses($article);

        $bodyStep = new Body($o);
        $originalScripts = $this->scriptWeight($doc);
        $work = $bodyStep->workingCopy($doc, $paywallClasses);
        $workBody = HtmlParser::body($work);
        $pageTextLen = Dom::length($workBody);
        $pageLinkDensity = Dom::linkDensity($workBody, $pageTextLen);
        $trace?->note('page.text_chars', $pageTextLen);
        $trace?->note('page.link_density', round($pageLinkDensity, 3));

        // Title (39 to 41).
        [$title, $titleSource] = $this->title($meta, $article, $work, $siteName, $inputHost, $trace);

        // Page type, first pass (14 to 16).
        $type = $this->initialType($meta, $url, $trace);
        $articleData = $article !== null || $meta->microdataArticle;

        // Body (18 to 30).
        $body = new BodyResult();
        $liveUpdates = [];
        if ($type === PageType::Liveblog && $article !== null) {
            $liveUpdates = $this->liveUpdates($article);
        }
        if ($type->isNonArticle()) {
            $warnings[] = Warning::NotAnArticle;
        } elseif ($liveUpdates !== []) {
            [$body->html, $body->text] = $bodyStep->liveblog($liveUpdates, $base);
            $body->source = Source::JsonLd;
            $body->confidence = $this->w->get('confStructured');
            $body->visibleLength = Text::proseLength($body->text);
        } else {
            $body = $bodyStep->extract($work, $meta, $base, $title, $trace);
            array_push($warnings, ...$body->warnings);
        }
        $charCount = Text::proseLength($body->text);
        $min = $o->proseMinimum();

        // Page type, second pass (17, 20, 60).
        if (!$type->isNonArticle() && $charCount < $min && !$body->structuredMeetsMinimum) {
            if (
                ($type === PageType::Unknown || ($type === PageType::Article && !$articleData))
                && $pageLinkDensity > $o->linkDensityIndex() && count(Dom::byTag($workBody, 'a')) >= 5
            ) {
                $type = PageType::Index;
                $warnings[] = Warning::NotAnArticle;
                $trace?->note('type.reason', 'short body and link density above the index bar');
            } elseif ($originalScripts > 0 && $pageTextLen < 200 && $type !== PageType::Liveblog) {
                $type = PageType::ScriptRendered;
                $warnings[] = Warning::NeedsJavascript;
                $trace?->note('type.reason', 'almost no text but a script bundle');
            }
        }
        $noTypeSignal = false;
        if ($type === PageType::Unknown && $charCount >= $min) {
            $type = PageType::Article;
            $noTypeSignal = true;
            $trace?->note('type.reason', 'no type signal; body meets the prose minimum');
        }
        if ($accessible === false && $charCount < $min && ($type === PageType::Article || $type === PageType::Unknown)) {
            $type = PageType::Paywalled;
            $warnings[] = Warning::Paywalled;
        }
        if ($type->isNonArticle() || $type === PageType::ScriptRendered) {
            $body = $this->emptyBody($body);
            $charCount = 0;
        }

        // Robots meta and header directives (72).
        $robots = $context?->robots;
        if ($robots !== null && $robots->refusesText()) {
            $body = $this->emptyBody($body);
            $charCount = 0;
            $warnings[] = Warning::RobotsMeta;
        }

        // Authors (42 to 46).
        [$authors, $editors, $authorSource, $badAuthor] = $this->authors($meta, $article, $work, $trace);
        if ($badAuthor) {
            $warnings[] = Warning::BadAuthor;
        }

        // Dates (47 to 52).
        $dates = new Dates($o->defaultTimezone(), $context?->referenceTime, $o->futureTolerance());
        [$published, $publishedSource, $pw] = $this->date('published', $dates, $meta, $article, $work, $trace);
        [$modified, , $mw] = $this->date('modified', $dates, $meta, $article, $work, $trace);
        if ($liveUpdates !== []) {
            [$published, $modified] = $this->liveDates($dates, $article, $liveUpdates, $published, $modified);
            if ($published !== null && $publishedSource === Source::None) {
                $publishedSource = Source::JsonLd;
            }
        }
        foreach (array_merge($pw, $mw) as $wn) {
            $warnings[] = $wn;
        }
        if ($published !== null && $modified !== null && $modified < $published) {
            $modified = null;
            $warnings[] = Warning::ModifiedBeforePublished;
        }

        // Lead image (37, 38).
        $lead = null;
        if ($robots === null || !$robots->refusesImages()) {
            $lead = $this->leadImage($meta, $article, $body->html, $base, $trace);
        } else {
            $warnings[] = Warning::RobotsMeta;
        }
        if ($lead === null) {
            $warnings[] = Warning::NoLeadImage;
        }

        // Canonical, AMP, host (53 to 57).
        [$canonical, $amp, $cw] = $this->canonical($meta, $article, $url, $base, $inputHost, $trace);
        array_push($warnings, ...$cw);
        $host = null;
        if ($canonical !== null) {
            $h = (string) Url::host($canonical);
            $norm = $o->hostNormaliser();
            $host = $norm !== null ? (string) $norm($h) : Url::normaliseHost($h, $o->useIntl());
        }

        // Language and script (58).
        $language = $this->language($meta, $article, $context);
        $detected = null;
        if ($body->text !== '') {
            [$script, $share] = Scripts::dominant($body->text);
            $detected = $script;
            if (
                $language !== null && str_starts_with(strtolower($language), 'en') && $script !== null
                && in_array($script, Scripts::INDIC, true) && $share > 0.5
            ) {
                $warnings[] = Warning::LanguageMismatch;
            }
        }

        // Sponsored signals (61).
        $sponsored = $this->sponsored($meta, $article, $work);

        // Counts (30).
        [$wordCount, $method] = WordCounter::count($body->text, $o->useIntl());

        // Confidence (62).
        $warnings = $this->uniqueWarnings($warnings);
        $confidence = $body->confidence;
        foreach (
            [
            'factorBodyDisagreement' => Warning::BodyDisagreement,
            'factorVisibleBodyShort' => Warning::VisibleBodyShort,
            'factorBiographyOnly' => Warning::BiographyOnly,
            'factorNotAnArticle' => Warning::NotAnArticle,
            ] as $factor => $wn
        ) {
            if (in_array($wn, $warnings, true)) {
                $confidence *= $this->w->get($factor);
            }
        }
        if ($noTypeSignal) {
            $confidence *= $this->w->get('factorNoTypeSignal');
        }
        $confidence = max(0.0, min(1.0, $confidence));
        $trace?->note('confidence', round($confidence, 4));

        $bodySource = $body->text === '' ? Source::None : $body->source;
        $fieldSources = [
            'body' => $bodySource,
            'title' => $titleSource,
            'authors' => $authorSource,
            'publishedAt' => $publishedSource,
            'leadImage' => $lead->source ?? Source::None,
        ];
        $fieldConfidence = [
            'body' => $body->text === '' ? 0.0 : round(max(0.0, min(1.0, $body->confidence)), 4),
            'title' => $this->sourceConfidence($titleSource, $title !== null && $titleSource === Source::Visible ? 0.7 : null),
            'authors' => $this->sourceConfidence($authorSource),
            'publishedAt' => $this->sourceConfidence($publishedSource) * (in_array(Warning::AssumedTimezone, $warnings, true) ? 0.8 : 1.0),
            'leadImage' => $this->sourceConfidence($lead->source ?? Source::None),
        ];

        return new Article(
            pageType: $type,
            title: $title,
            bodyHtml: $body->html,
            bodyText: $body->text,
            wordCount: $wordCount,
            charCount: $charCount,
            wordCountMethod: $method,
            authors: $authors,
            editors: $editors,
            publishedAt: $published,
            modifiedAt: $modified,
            leadImage: $lead,
            canonicalUrl: $canonical,
            ampUrl: $amp,
            host: $host,
            language: $language,
            detectedScript: $detected,
            siteName: $siteName,
            accessibleForFree: $accessible,
            sponsoredSignals: $sponsored,
            warnings: $warnings,
            fieldSources: $fieldSources,
            fieldConfidence: $fieldConfidence,
            confidence: round($confidence, 4),
            trace: $trace,
            proseMinimum: $min,
            readableBar: $o->readableBar(),
        );
    }

    /** @param list<Warning> $warnings */
    private function bare(PageType $type, array $warnings, ?Trace $trace): Article
    {
        $none = Source::None;
        return new Article(
            pageType: $type,
            title: null,
            bodyHtml: '',
            bodyText: '',
            wordCount: 0,
            charCount: 0,
            wordCountMethod: WordCounter::WHITESPACE,
            authors: [],
            editors: [],
            publishedAt: null,
            modifiedAt: null,
            leadImage: null,
            canonicalUrl: null,
            ampUrl: null,
            host: null,
            language: null,
            detectedScript: null,
            siteName: null,
            accessibleForFree: null,
            sponsoredSignals: [],
            warnings: $this->uniqueWarnings($warnings),
            fieldSources: ['body' => $none, 'title' => $none, 'authors' => $none, 'publishedAt' => $none, 'leadImage' => $none],
            fieldConfidence: ['body' => 0.0, 'title' => 0.0, 'authors' => 0.0, 'publishedAt' => 0.0, 'leadImage' => 0.0],
            confidence: 0.0,
            trace: $trace,
            proseMinimum: $this->o->proseMinimum(),
            readableBar: $this->o->readableBar(),
        );
    }

    private function emptyBody(BodyResult $body): BodyResult
    {
        $b = new BodyResult();
        $b->warnings = $body->warnings;
        $b->container = $body->container;
        return $b;
    }

    /**
     * @param list<Warning> $warnings
     * @return list<Warning>
     */
    private function uniqueWarnings(array $warnings): array
    {
        $out = [];
        foreach ($warnings as $w) {
            if (!in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        return $out;
    }

    private function sourceConfidence(Source $s, ?float $override = null): float
    {
        if ($override !== null) {
            return $override;
        }
        return match ($s) {
            Source::JsonLd => 0.95,
            Source::Microdata => 0.9,
            Source::Meta => 0.85,
            Source::Visible => 0.6,
            Source::None => 0.0,
        };
    }

    private function isWrapper(string $url): bool
    {
        $host = Url::host($url);
        if ($host === null) {
            return false;
        }
        $prefixes = $this->o->wrapperHosts()[$host] ?? null;
        if ($prefixes === null) {
            return false;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach ($prefixes as $p) {
            if (str_starts_with($path, $p)) {
                return true;
            }
        }
        return false;
    }

    private function scriptWeight(\DOMDocument $doc): int
    {
        $n = 0;
        foreach (Dom::byTag(HtmlParser::body($doc), 'script') as $s) {
            $type = strtolower($s->getAttribute('type'));
            if (str_contains($type, 'json')) {
                continue;
            }
            if ($s->hasAttribute('src') || strlen($s->textContent) > 500) {
                $n++;
            }
        }
        return $n;
    }

    private function siteName(Metadata $meta, string $host): ?string
    {
        $og = $meta->firstMeta('og:site_name');
        if ($og !== null) {
            return $og;
        }
        if ($meta->article !== null && isset($meta->article['publisher'])) {
            $p = $meta->article['publisher'];
            $name = is_array($p) ? Metadata::str($p['name'] ?? null) : Metadata::str($p);
            if ($name !== null) {
                return $name;
            }
        }
        return $host !== '' ? Url::normaliseHost($host, false) : null;
    }

    /** @param array<string, mixed>|null $article */
    private function accessibleForFree(?array $article): ?bool
    {
        if ($article === null || !array_key_exists('isAccessibleForFree', $article)) {
            return null;
        }
        $v = $article['isAccessibleForFree'];
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $v = strtolower(trim($v));
            if (in_array($v, ['false', 'no', '0', 'http://schema.org/false', 'https://schema.org/false'], true)) {
                return false;
            }
            if (in_array($v, ['true', 'yes', '1', 'http://schema.org/true', 'https://schema.org/true'], true)) {
                return true;
            }
        }
        return null;
    }

    /**
     * Classes marked not free by JSON-LD hasPart cssSelector (".paywall").
     *
     * @param array<string, mixed>|null $article
     * @return list<string>
     */
    private function paywallClasses(?array $article): array
    {
        if ($article === null || !isset($article['hasPart']) || !is_array($article['hasPart'])) {
            return [];
        }
        $parts = array_is_list($article['hasPart']) ? $article['hasPart'] : [$article['hasPart']];
        $out = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $free = $this->accessibleForFree($part);
            $sel = $part['cssSelector'] ?? null;
            if ($free === false && is_string($sel)) {
                foreach (explode(',', $sel) as $one) {
                    $one = trim($one);
                    if (preg_match('/^\.([A-Za-z0-9_\-]+)$/', $one, $m) === 1) {
                        $out[] = strtolower($m[1]);
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed>|null $article
     * @return array{0: ?string, 1: Source}
     */
    private function title(Metadata $meta, ?array $article, \DOMDocument $work, ?string $siteName, string $host, ?Trace $trace): array
    {
        $cands = [];
        if ($article !== null) {
            $cands[] = [Metadata::str($article['headline'] ?? ($article['name'] ?? null)), Source::JsonLd, 'json_ld headline'];
        }
        $cands[] = [$meta->firstMeta('og:title'), Source::Meta, 'og:title'];
        $cands[] = [$this->singleH1($work), Source::Visible, 'h1'];
        $cands[] = [$meta->firstMeta('twitter:title'), Source::Meta, 'twitter:title'];
        $titleEl = $work->getElementsByTagName('title')->item(0);
        $cands[] = [$titleEl !== null ? Dom::text($titleEl) : null, Source::Visible, 'title element'];
        $chosen = null;
        $source = Source::None;
        foreach ($cands as [$value, $src, $label]) {
            if ($value === null) {
                continue;
            }
            $value = Text::line(Text::decodeEntities($value));
            if ($value === '') {
                continue;
            }
            $stripped = TitleRules::strip($value, $siteName, $host);
            if ($chosen === null) {
                $chosen = $stripped;
                $source = $src;
                $trace?->candidate('title', $src, $stripped, true, $label . ($stripped !== $value ? ' (site name stripped)' : ''));
            } else {
                $trace?->candidate('title', $src, $stripped, false, $label . ': lower in order');
            }
        }
        return [$chosen, $source];
    }

    private function singleH1(\DOMDocument $work): ?string
    {
        $h1s = Dom::byTag(HtmlParser::body($work), 'h1');
        if ($h1s === []) {
            return null;
        }
        if (count($h1s) === 1) {
            return Dom::text($h1s[0]);
        }
        foreach ($h1s as $h) {
            for ($p = $h->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                if (in_array(Dom::name($p), ['article', 'main'], true)) {
                    return Dom::text($h);
                }
            }
        }
        return Dom::text($h1s[0]);
    }

    private function initialType(Metadata $meta, string $url, ?Trace $trace): PageType
    {
        $type = PageType::Unknown;
        $why = 'no signal';
        if ($meta->structuredType !== null) {
            $type = PageType::from($meta->structuredType);
            $why = 'json_ld @type';
        } else {
            $og = strtolower((string) $meta->firstMeta('og:type'));
            if ($og === 'article') {
                $type = PageType::Article;
                $why = 'og:type article';
            } elseif (str_starts_with($og, 'video')) {
                $type = PageType::Video;
                $why = 'og:type video';
            }
        }
        $articleData = $meta->article !== null || $meta->microdataArticle;
        if (($type === PageType::Unknown || $type === PageType::Article) && !$articleData) {
            $hints = $this->o->nonArticlePathHints();
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            foreach (explode('/', $path) as $seg) {
                if ($seg !== '' && isset($hints[$seg])) {
                    $type = PageType::from($hints[$seg]);
                    $why = 'path segment /' . $seg . '/';
                    break;
                }
            }
        }
        $trace?->note('type.initial', $type->value . ' (' . $why . ')');
        return $type;
    }

    /**
     * @param array<string, mixed> $article
     * @return list<array{time: ?string, headline: ?string, body: string, date: ?string}>
     */
    private function liveUpdates(array $article): array
    {
        $raw = $article['liveBlogUpdate'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $items = array_is_list($raw) ? $raw : [$raw];
        $out = [];
        foreach ($items as $u) {
            if (!is_array($u)) {
                continue;
            }
            $body = is_string($u['articleBody'] ?? null) ? $u['articleBody'] : (Metadata::str($u['text'] ?? null) ?? '');
            $headline = Metadata::str($u['headline'] ?? null);
            if (trim($body) === '' && $headline === null) {
                continue;
            }
            $date = Metadata::str($u['datePublished'] ?? ($u['dateCreated'] ?? null));
            $out[] = ['time' => $date, 'headline' => $headline, 'body' => $body, 'date' => $date];
        }
        return $out;
    }

    /**
     * @param array<string, mixed>|null $article
     * @param list<array{time: ?string, headline: ?string, body: string, date: ?string}> $updates
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function liveDates(Dates $dates, ?array $article, array $updates, ?\DateTimeImmutable $published, ?\DateTimeImmutable $modified): array
    {
        $times = [];
        foreach ($updates as $u) {
            if ($u['date'] !== null) {
                $r = $dates->machine($u['date']);
                if ($r !== null && $dates->isPlausible($r['date'])) {
                    $times[] = $r['date'];
                }
            }
        }
        if ($article !== null && $published === null) {
            $start = Metadata::str($article['coverageStartTime'] ?? null);
            $r = $start !== null ? $dates->machine($start) : null;
            if ($r !== null) {
                $published = $r['date'];
            }
        }
        if ($times !== []) {
            $published ??= min($times);
            $newest = max($times);
            if ($modified === null || $newest > $modified) {
                $modified = $newest;
            }
        }
        return [$published, $modified];
    }

    /**
     * @param array<string, mixed>|null $article
     * @return array{0: list<Author>, 1: list<Author>, 2: Source, 3: bool}
     */
    private function authors(Metadata $meta, ?array $article, \DOMDocument $work, ?Trace $trace): array
    {
        $parser = new Authors($this->o);
        $bylineEl = $this->bylineElement($work);
        $byline = $bylineEl !== null ? $parser->fromByline(Dom::text($bylineEl)) : ['authors' => [], 'editors' => []];

        $sources = [];
        if ($article !== null && isset($article['author'])) {
            $sources[] = [Source::JsonLd, 'json_ld author', fn (): array => $parser->fromJsonLd($article['author'])];
        }
        $metaAuthor = $meta->firstMeta('author');
        if ($metaAuthor !== null) {
            $sources[] = [Source::Meta, 'meta author', fn (): array => $parser->fromByline($metaAuthor)['authors']];
        }
        foreach ($meta->meta['article:author'] ?? [] as $aa) {
            if (!Url::isHttp($aa) && !str_contains($aa, '/')) {
                $sources[] = [Source::Meta, 'article:author', fn (): array => $parser->fromByline($aa)['authors']];
            }
        }
        $rel = [];
        foreach ($meta->links['author'] ?? [] as $link) {
            if ($link['text'] !== '') {
                $rel[] = new Author($link['text'], Url::isHttp($link['href']) ? $link['href'] : null, $parser->kind($link['text'], null));
            }
        }
        if ($rel !== []) {
            $sources[] = [Source::Visible, 'rel=author', fn (): array => $rel];
        }
        if ($byline['authors'] !== []) {
            $sources[] = [Source::Visible, 'byline element', fn (): array => $byline['authors']];
        }
        $chosen = [];
        $source = Source::None;
        $bad = false;
        foreach ($sources as [$src, $label, $fn]) {
            $before = count($parser->rejected);
            $list = $parser->clean($fn());
            $names = implode(', ', array_map(static fn (Author $a): string => $a->name, $list));
            if ($chosen === [] && $list !== []) {
                $chosen = $list;
                $source = $src;
                $bad = count($parser->rejected) > $before;
                $trace?->candidate('authors', $src, $names, true, $label);
            } else {
                $trace?->candidate('authors', $src, $names, false, $label . ($list === [] ? ': nothing usable' : ': lower in order'));
            }
        }
        if ($chosen === [] && $parser->rejected !== []) {
            $bad = true;
        }
        $editors = $parser->clean($byline['editors']);
        return [$chosen, $editors, $source, $bad];
    }

    private function bylineElement(\DOMDocument $work): ?\DOMElement
    {
        foreach (Dom::all(HtmlParser::body($work)) as $el) {
            $h = Dom::hintString($el);
            if ($h === '' || str_contains($h, 'bio') || str_contains($h, 'profile')) {
                continue;
            }
            if (str_contains($h, 'byline') || str_contains($h, 'author-name') || str_contains($h, 'author')) {
                $t = Dom::text($el);
                if ($t !== '' && Text::length($t) < 200) {
                    return $el;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed>|null $article
     * @return array{0: ?\DateTimeImmutable, 1: Source, 2: list<Warning>}
     */
    private function date(string $which, Dates $dates, Metadata $meta, ?array $article, \DOMDocument $work, ?Trace $trace): array
    {
        $pub = $which === 'published';
        $machine = [];
        if ($article !== null) {
            $machine[] = [Metadata::str($article[$pub ? 'datePublished' : 'dateModified'] ?? null), Source::JsonLd, 'json_ld'];
        }
        $keys = $pub
            ? ['article:published_time', 'og:article:published_time', 'datepublished', 'publish-date', 'pubdate']
            : ['article:modified_time', 'og:article:modified_time', 'og:updated_time', 'datemodified', 'last-modified'];
        foreach ($keys as $k) {
            foreach ($meta->meta[$k] ?? [] as $v) {
                $machine[] = [$v, $k === 'datepublished' || $k === 'datemodified' ? Source::Microdata : Source::Meta, $k];
            }
        }
        $machine[] = [$meta->itempropValue($pub ? 'datePublished' : 'dateModified'), Source::Microdata, 'itemprop'];
        $machine[] = [$this->timeElement($work, $pub), Source::Visible, 'time element'];

        $warnings = [];
        foreach ($machine as [$raw, $src, $label]) {
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            $r = $dates->machine($raw) ?? $dates->visible($raw);
            if ($r === null || $r['date'] === null) {
                $trace?->candidate($which . 'At', $src, $raw, false, $label . ': unparsed');
                continue;
            }
            if (!$dates->isPlausible($r['date'])) {
                $warnings[] = Warning::ImplausibleDate;
                $trace?->candidate($which . 'At', $src, $raw, false, $label . ': implausible');
                continue;
            }
            if ($r['assumed']) {
                $warnings[] = Warning::AssumedTimezone;
            }
            $trace?->candidate($which . 'At', $src, $raw, true, $label);
            return [$r['date'], $src, $warnings];
        }

        foreach ($this->visibleDateTexts($work, $pub) as $raw) {
            $r = $dates->visible($raw);
            if ($r === null) {
                continue;
            }
            if ($r['relative'] && $r['date'] === null) {
                $warnings[] = Warning::RelativeDate;
                $trace?->candidate($which . 'At', Source::Visible, $raw, false, 'relative date without a reference time');
                continue;
            }
            if ($r['date'] === null) {
                continue;
            }
            if (!$dates->isPlausible($r['date'])) {
                $warnings[] = Warning::ImplausibleDate;
                $trace?->candidate($which . 'At', Source::Visible, $raw, false, 'visible: implausible');
                continue;
            }
            if ($r['assumed']) {
                $warnings[] = Warning::AssumedTimezone;
            }
            $trace?->candidate($which . 'At', Source::Visible, $raw, true, 'visible date text');
            return [$r['date'], Source::Visible, $warnings];
        }
        return [null, Source::None, $warnings];
    }

    private function timeElement(\DOMDocument $work, bool $published): ?string
    {
        foreach (Dom::byTag(HtmlParser::body($work), 'time') as $t) {
            if (!$t->hasAttribute('datetime')) {
                continue;
            }
            $hint = Dom::hintString($t) . ' ' . strtolower($t->getAttribute('itemprop'));
            $isUpdate = str_contains($hint, 'updat') || str_contains($hint, 'modif');
            if ($published === $isUpdate) {
                continue;
            }
            $inHeader = false;
            for ($p = $t->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                if (
                    in_array(Dom::name($p), ['header', 'article', 'main'], true)
                    || str_contains(Dom::hintString($p), 'meta') || str_contains(Dom::hintString($p), 'byline')
                ) {
                    $inHeader = true;
                    break;
                }
            }
            if ($inHeader || $hint !== ' ') {
                return $t->getAttribute('datetime');
            }
        }
        return null;
    }

    /** @return list<string> */
    private function visibleDateTexts(\DOMDocument $work, bool $published): array
    {
        $primary = [];
        $secondary = [];
        foreach (Dom::all(HtmlParser::body($work)) as $el) {
            $h = Dom::hintString($el);
            if ($h === '' || !preg_match('/date|time|publish|updated|posted|dateline|timestamp/', $h)) {
                continue;
            }
            $t = Dom::text($el);
            if ($t === '' || Text::length($t) > 200) {
                continue;
            }
            $isUpdate = str_contains($h, 'updat') || preg_match('/^(last\s+)?updated|^modified|^\x{0905}\x{092A}\x{0921}\x{0947}\x{091F}/iu', $t) === 1;
            if ($published) {
                if ($isUpdate) {
                    $secondary[] = $t;
                } else {
                    $primary[] = $t;
                }
            } elseif ($isUpdate) {
                $primary[] = $t;
            }
            if (count($primary) >= 5) {
                break;
            }
        }
        return array_values(array_unique(array_merge($primary, $secondary)));
    }

    /**
     * @param array<string, mixed>|null $article
     */
    private function leadImage(Metadata $meta, ?array $article, string $bodyHtml, string $base, ?Trace $trace): ?Image
    {
        $rules = new ImageRules($this->o);
        $cands = [];
        if ($article !== null && isset($article['image'])) {
            foreach ($this->jsonLdImages($article['image']) as $c) {
                $cands[] = [$c[0], $c[1], $c[2], Source::JsonLd, 'json_ld image'];
            }
        }
        $ogW = $meta->firstMeta('og:image:width');
        $ogH = $meta->firstMeta('og:image:height');
        $first = true;
        foreach (['og:image', 'og:image:secure_url', 'og:image:url'] as $k) {
            foreach ($meta->meta[$k] ?? [] as $v) {
                $cands[] = [$v, $first && $ogW !== null && ctype_digit($ogW) ? (int) $ogW : null,
                    $first && $ogH !== null && ctype_digit($ogH) ? (int) $ogH : null, Source::Meta, $k];
                $first = false;
            }
        }
        foreach (['twitter:image', 'twitter:image:src'] as $k) {
            foreach ($meta->meta[$k] ?? [] as $v) {
                $cands[] = [$v, null, null, Source::Meta, $k];
            }
        }
        foreach ($meta->links['image_src'] ?? [] as $l) {
            $cands[] = [$l['href'], null, null, Source::Meta, 'link image_src'];
        }
        if ($bodyHtml !== '') {
            $frag = HtmlParser::fragment($bodyHtml);
            foreach (Dom::byTag($frag, 'img') as $img) {
                $cands[] = [$img->getAttribute('src'), Dom::attrInt($img, 'width'), Dom::attrInt($img, 'height'), Source::Visible, 'body image'];
            }
        }
        foreach ($cands as [$raw, $w, $h, $src, $label]) {
            $u = Url::resolve($base, $raw);
            $reason = $rules->rejectReason($u, $w, $h);
            if ($reason === null && $u !== null) {
                $trace?->candidate('leadImage', $src, $u, true, $label);
                return new Image($u, $w, $h, $src);
            }
            $trace?->candidate('leadImage', $src, (string) $raw, false, $label . ': ' . $reason);
        }
        return null;
    }

    /**
     * JSON-LD image values, largest first when sizes are known.
     *
     * @return list<array{0: string, 1: ?int, 2: ?int}>
     */
    private function jsonLdImages(mixed $value): array
    {
        $items = is_array($value) && array_is_list($value) ? $value : [$value];
        $out = [];
        foreach ($items as $i => $item) {
            if (is_string($item)) {
                $out[] = [$item, null, null, $i];
            } elseif (is_array($item)) {
                $u = Metadata::str($item['url'] ?? ($item['contentUrl'] ?? ($item['@id'] ?? null)), 'url');
                if ($u !== null) {
                    $w = isset($item['width']) ? (int) (is_array($item['width']) ? ($item['width']['value'] ?? 0) : $item['width']) : null;
                    $h = isset($item['height']) ? (int) (is_array($item['height']) ? ($item['height']['value'] ?? 0) : $item['height']) : null;
                    $out[] = [$u, $w ?: null, $h ?: null, $i];
                }
            }
        }
        usort($out, static function (array $a, array $b): int {
            $aa = ($a[1] ?? 0) * ($a[2] ?? 0);
            $bb = ($b[1] ?? 0) * ($b[2] ?? 0);
            return [$bb, $a[3]] <=> [$aa, $b[3]];
        });
        return array_map(static fn (array $x): array => [$x[0], $x[1], $x[2]], $out);
    }

    /**
     * @param array<string, mixed>|null $article
     * @return array{0: ?string, 1: ?string, 2: list<Warning>}
     */
    private function canonical(Metadata $meta, ?array $article, string $url, string $base, string $inputHost, ?Trace $trace): array
    {
        $warnings = [];
        $cands = [];
        foreach ($meta->links['canonical'] ?? [] as $l) {
            $cands[] = [$l['href'], 'link canonical'];
        }
        $og = $meta->firstMeta('og:url');
        if ($og !== null) {
            $cands[] = [$og, 'og:url'];
        }
        if ($article !== null) {
            foreach (['url', 'mainEntityOfPage'] as $k) {
                $v = Metadata::str($article[$k] ?? null, '@id');
                if ($v !== null) {
                    $cands[] = [$v, 'json_ld ' . $k];
                }
            }
        }
        $cands[] = [$url, 'input url'];
        $inputNorm = Url::normaliseHost($inputHost, false);
        $own = array_map(static fn (string $h): string => Url::normaliseHost($h, false), $this->o->ownHosts());
        $inputIsHome = Url::isHomePage($url);
        $chosen = null;
        foreach ($cands as [$raw, $label]) {
            $u = Url::resolve($base, $raw);
            if ($u === null || !Url::isHttp($u)) {
                $warnings[] = Warning::CanonicalIgnored;
                $trace?->candidate('canonicalUrl', $label, $raw, false, 'not an http URL');
                continue;
            }
            $h = Url::normaliseHost((string) Url::host($u), false);
            if (in_array($h, $own, true)) {
                $warnings[] = Warning::OwnHost;
                $trace?->candidate('canonicalUrl', $label, $u, false, 'own host');
                continue;
            }
            if ($label !== 'input url') {
                if (!Url::sameOutlet($h, $inputNorm)) {
                    $warnings[] = Warning::CanonicalIgnored;
                    $trace?->candidate('canonicalUrl', $label, $u, false, 'another outlet');
                    continue;
                }
                if (Url::isHomePage($u) && !$inputIsHome) {
                    $warnings[] = Warning::CanonicalIgnored;
                    $trace?->candidate('canonicalUrl', $label, $u, false, 'home page');
                    continue;
                }
            }
            $chosen = Url::stripTracking($u, $this->o->trackingParams());
            $trace?->candidate('canonicalUrl', $label, $chosen, true, 'accepted');
            break;
        }
        $amp = null;
        if ($meta->amp) {
            $amp = Url::stripTracking($url, $this->o->trackingParams());
            if ($chosen === $amp) {
                $trace?->note('amp', 'AMP page without a usable canonical');
            }
        }
        return [$chosen, $amp, $warnings];
    }

    /** @param array<string, mixed>|null $article */
    private function language(Metadata $meta, ?array $article, ?PageContext $context): ?string
    {
        $cands = [];
        if ($article !== null && isset($article['inLanguage'])) {
            $v = $article['inLanguage'];
            $cands[] = is_array($v) ? Metadata::str($v['alternateName'] ?? ($v['name'] ?? null)) : Metadata::str($v);
        }
        $cands[] = $meta->htmlLang;
        $cands[] = $meta->firstMeta('og:locale');
        $cands[] = $context?->header('content-language');
        $cands[] = $meta->firstMeta('content-language');
        foreach ($cands as $c) {
            if ($c === null) {
                continue;
            }
            $tag = $this->bcp47(explode(',', $c)[0]);
            if ($tag !== null) {
                return $tag;
            }
        }
        return null;
    }

    private function bcp47(string $raw): ?string
    {
        $raw = trim(str_replace('_', '-', $raw));
        if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/i', $raw) !== 1) {
            return null;
        }
        $parts = explode('-', $raw);
        $out = [strtolower($parts[0])];
        foreach (array_slice($parts, 1) as $p) {
            if (strlen($p) === 2 && ctype_alpha($p)) {
                $out[] = strtoupper($p);
            } elseif (strlen($p) === 4 && ctype_alpha($p)) {
                $out[] = ucfirst(strtolower($p));
            } else {
                $out[] = strtolower($p);
            }
        }
        return implode('-', $out);
    }

    /**
     * @param array<string, mixed>|null $article
     * @return list<string>
     */
    private function sponsored(Metadata $meta, ?array $article, \DOMDocument $work): array
    {
        $out = [];
        foreach ($meta->nodes as $node) {
            if (Metadata::hasType($node, 'AdvertiserContentArticle')) {
                $out[] = 'json_ld:AdvertiserContentArticle';
            }
        }
        if ($article !== null && isset($article['sponsor'])) {
            $out[] = 'json_ld:sponsor';
        }
        foreach (['sponsored', 'article:sponsor', 'article:content_tier'] as $k) {
            $v = $meta->firstMeta($k);
            if ($v !== null && ($k !== 'article:content_tier' || str_contains(strtolower($v), 'sponsor'))) {
                $out[] = 'meta:' . $k;
            }
        }
        $labels = $this->o->sponsoredLabels();
        $xp = new \DOMXPath($work);
        $nodes = $xp->query('//body//text()');
        if ($nodes !== false) {
            foreach ($nodes as $tn) {
                if (!$tn instanceof \DOMText) {
                    continue;
                }
                $t = Text::lower(trim(Text::line($tn->textContent), " :|-\u{2013}"));
                if ($t !== '' && strlen($t) < 60 && in_array($t, $labels, true)) {
                    $out[] = 'label:' . Text::line($tn->textContent);
                }
            }
        }
        foreach (Dom::all(HtmlParser::body($work)) as $el) {
            $tokens = preg_split('/\s+/', Dom::hintString($el)) ?: [];
            foreach ($tokens as $tok) {
                if ($tok !== '' && preg_match('/^(sponsored|advertorial|partner-content|brand-studio|paid-post)(-|$)/', $tok) === 1) {
                    $out[] = 'class:' . $tok;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
