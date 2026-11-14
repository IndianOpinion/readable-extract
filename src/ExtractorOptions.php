<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

use IndianOpinion\ReadableExtract\Exception\ConfigurationException;

/**
 * Extraction settings. Immutable: every with*() returns a changed copy.
 * The defaults are the ones in the README configuration table.
 */
final class ExtractorOptions
{
    public const DEFAULT_LAZY_ATTRIBUTES = [
        'data-src', 'data-lazy-src', 'data-original', 'data-hi-res-src', 'data-image', 'data-url',
    ];

    public const DEFAULT_PLACEHOLDER_NAMES = [
        'spacer', 'blank', 'pixel', 'placeholder', 'transparent', 'empty', 'dummy', 'noimage',
        'no-image', 'lazy', 'lazyload', 'loading', 'grey', 'gray', 'dot', 'px',
    ];

    public const DEFAULT_CHROME_WORDS = [
        'logo', 'icon', 'avatar', 'sprite', 'emoji', 'badge', 'button', 'btn', 'arrow', 'watermark',
        'lightbox',
    ];

    public const DEFAULT_POSITIVE_HINTS = ['article', 'story', 'content', 'body', 'entry', 'post'];

    public const DEFAULT_NEGATIVE_HINTS = [
        'comment', 'related', 'share', 'social', 'newsletter', 'promo', 'sponsor', 'advert', 'widget',
        'sidebar', 'breadcrumb', 'author-bio', 'profile', 'trending', 'also-read', 'read-more',
    ];

    /** Path segment => page type it suggests when no article structured data is present. */
    public const DEFAULT_NON_ARTICLE_PATH_HINTS = [
        'video' => 'video',
        'videos' => 'video',
        'photos' => 'gallery',
        'photo-gallery' => 'gallery',
        'gallery' => 'gallery',
        'web-stories' => 'gallery',
        'tag' => 'index',
        'topic' => 'index',
        'author' => 'index',
        'section' => 'index',
    ];

    public const DEFAULT_AGENCY_PATTERNS = [
        'news service', 'news agency', 'desk', 'bureau', 'agencies', 'agency', 'web desk', 'online desk',
        'news desk', 'wire', 'wires', 'staff reporter', 'our correspondent', 'ब्यूरो', 'डेस्क',
    ];

    public const DEFAULT_BIOGRAPHY_PATTERNS = [
        'is a', 'is an', 'writes on', 'writes about', 'covers', 'reports on', 'has been', 'is the author',
        'is a senior', 'is a journalist',
    ];

    public const DEFAULT_TRACKING_PARAMS = [
        'utm_*', 'fbclid', 'gclid', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'yclid', 'mc_cid', 'mc_eid',
        'igshid', '_ga', '_gl', 'ref_src', 'ref_url', 'cmpid', 'ito', 'icid',
    ];

    public const DEFAULT_ALSO_READ_PHRASES = [
        'also read', 'read also', 'read more', 'also see', 'also watch', 'must read', 'recommended',
        'related:', 'related stories', 'ये भी पढ़ें', 'यह भी पढ़ें', 'इसे भी पढ़ें', 'और पढ़ें', 'पढ़ें:',
    ];

    public const DEFAULT_BOILERPLATE_PHRASES = [
        'subscribe to our newsletter', 'sign up for our newsletter', 'download the app', 'download our app',
        'follow us on', 'join our whatsapp', 'join our telegram', 'click here to subscribe',
        'share this article', 'share on', 'get the latest news', 'subscribe to read', 'subscribe to continue',
    ];

    public const DEFAULT_SPONSORED_LABELS = [
        'sponsored', 'sponsored content', 'sponsored post', 'partner content', 'in partnership with',
        'advertorial', 'brand studio', 'press release', 'paid post', 'promoted', 'brand connect',
    ];

    /** Aggregator wrapper hosts => path prefixes that mark a wrapper link. */
    public const DEFAULT_WRAPPER_HOSTS = [
        'news.google.com' => ['/rss/articles/', '/articles/', '/read/', '/__i/rss/rd/articles/'],
    ];

    private int $proseMinimum = 400;
    private float $readableBar = 0.5;
    private int $inputCap = 5242880;
    private float $linkDensityIndex = 0.5;
    private float $bodyAgreement = 0.3;
    private int $furnitureMinWidth = 200;
    private int $furnitureMinHeight = 120;
    /** @var list<string> */
    private array $lazyAttributes = self::DEFAULT_LAZY_ATTRIBUTES;
    /** @var list<string> */
    private array $placeholderNames = self::DEFAULT_PLACEHOLDER_NAMES;
    /** @var list<string> */
    private array $chromeWords = self::DEFAULT_CHROME_WORDS;
    /** @var list<string> */
    private array $positiveHints = self::DEFAULT_POSITIVE_HINTS;
    /** @var list<string> */
    private array $negativeHints = self::DEFAULT_NEGATIVE_HINTS;
    /** @var array<string, string> */
    private array $nonArticlePathHints = self::DEFAULT_NON_ARTICLE_PATH_HINTS;
    /** @var list<string> */
    private array $agencyPatterns = self::DEFAULT_AGENCY_PATTERNS;
    /** @var list<string> */
    private array $biographyPatterns = self::DEFAULT_BIOGRAPHY_PATTERNS;
    private string $defaultTimezone = 'Asia/Kolkata';
    private int $futureTolerance = 86400;
    /** @var list<string> */
    private array $trackingParams = self::DEFAULT_TRACKING_PARAMS;
    /** @var list<string> */
    private array $ownHosts = [];
    /** @var (\Closure(string): string)|null */
    private ?\Closure $hostNormaliser = null;
    private bool $trace = false;
    /** @var array<string, int|float> */
    private array $scoringWeights = [];
    /** @var array<string, list<string>> */
    private array $wrapperHosts = self::DEFAULT_WRAPPER_HOSTS;
    /** @var list<string> */
    private array $alsoReadPhrases = self::DEFAULT_ALSO_READ_PHRASES;
    /** @var list<string> */
    private array $boilerplatePhrases = self::DEFAULT_BOILERPLATE_PHRASES;
    /** @var list<string> */
    private array $sponsoredLabels = self::DEFAULT_SPONSORED_LABELS;
    private bool $useIntl = true;

    public static function defaults(): self
    {
        return new self();
    }

    public function withProseMinimum(int $chars): self
    {
        $this->positive($chars, 'proseMinimum');
        return $this->with('proseMinimum', $chars);
    }

    public function withReadableBar(float $bar): self
    {
        $this->unit($bar, 'readableBar');
        return $this->with('readableBar', $bar);
    }

    public function withInputCap(int $bytes): self
    {
        $this->positive($bytes, 'inputCap');
        return $this->with('inputCap', $bytes);
    }

    public function withLinkDensityIndex(float $ratio): self
    {
        $this->unit($ratio, 'linkDensityIndex');
        return $this->with('linkDensityIndex', $ratio);
    }

    public function withBodyAgreement(float $ratio): self
    {
        $this->unit($ratio, 'bodyAgreement');
        return $this->with('bodyAgreement', $ratio);
    }

    public function withFurnitureBar(int $minWidth, int $minHeight): self
    {
        $this->positive($minWidth, 'furnitureMinWidth');
        $this->positive($minHeight, 'furnitureMinHeight');
        return $this->with('furnitureMinWidth', $minWidth)->with('furnitureMinHeight', $minHeight);
    }

    /** @param list<string> $attributes Ordered: the first passing attribute wins. */
    public function withLazyAttributes(array $attributes): self
    {
        return $this->with('lazyAttributes', $this->lowerList($attributes, 'lazyAttributes'));
    }

    /** @param list<string> $names */
    public function withPlaceholderNames(array $names): self
    {
        return $this->with('placeholderNames', $this->lowerList($names, 'placeholderNames'));
    }

    /** @param list<string> $words */
    public function withChromeWords(array $words): self
    {
        return $this->with('chromeWords', $this->lowerList($words, 'chromeWords'));
    }

    /** @param list<string> $hints */
    public function withPositiveHints(array $hints): self
    {
        return $this->with('positiveHints', $this->lowerList($hints, 'positiveHints'));
    }

    /** @param list<string> $hints */
    public function withNegativeHints(array $hints): self
    {
        return $this->with('negativeHints', $this->lowerList($hints, 'negativeHints'));
    }

    /** @param array<string, string> $hints path segment => page type value (video, gallery, index) */
    public function withNonArticlePathHints(array $hints): self
    {
        $clean = [];
        foreach ($hints as $segment => $type) {
            $pt = PageType::tryFrom($type);
            if ($pt === null || !$pt->isNonArticle()) {
                throw new ConfigurationException('Path hint type must be video, gallery or index: ' . $type);
            }
            $clean[strtolower(trim((string) $segment, '/'))] = $type;
        }
        return $this->with('nonArticlePathHints', $clean);
    }

    /** @param list<string> $patterns */
    public function withAgencyPatterns(array $patterns): self
    {
        return $this->with('agencyPatterns', $this->lowerList($patterns, 'agencyPatterns'));
    }

    /** @param list<string> $patterns Phrases that follow a name in a biography ("is a", "writes on"). */
    public function withBiographyPatterns(array $patterns): self
    {
        return $this->with('biographyPatterns', $this->lowerList($patterns, 'biographyPatterns'));
    }

    public function withDefaultTimezone(string $zone): self
    {
        try {
            new \DateTimeZone($zone);
        } catch (\Exception $e) {
            throw new ConfigurationException('Unknown time zone: ' . $zone, 0, $e);
        }
        return $this->with('defaultTimezone', $zone);
    }

    public function withFutureTolerance(int $seconds): self
    {
        if ($seconds < 0) {
            throw new ConfigurationException('futureTolerance must not be negative');
        }
        return $this->with('futureTolerance', $seconds);
    }

    /** @param list<string> $params Names; a trailing * matches a prefix (utm_*). */
    public function withTrackingParams(array $params): self
    {
        return $this->with('trackingParams', $this->lowerList($params, 'trackingParams'));
    }

    /** @param list<string> $hosts The caller's own hosts; never reported as an article's source. */
    public function withOwnHosts(array $hosts): self
    {
        return $this->with('ownHosts', $this->lowerList($hosts, 'ownHosts'));
    }

    /** @param callable(string): string $normaliser */
    public function withHostNormaliser(callable $normaliser): self
    {
        return $this->with('hostNormaliser', \Closure::fromCallable($normaliser));
    }

    public function withTrace(bool $on = true): self
    {
        return $this->with('trace', $on);
    }

    /** @param array<string, int|float> $weights Keys from ScoringWeights::DEFAULTS. */
    public function withScoringWeights(array $weights): self
    {
        new ScoringWeights($weights);
        return $this->with('scoringWeights', $weights);
    }

    /** @param array<string, list<string>> $hosts host => path prefixes */
    public function withWrapperHosts(array $hosts): self
    {
        $clean = [];
        foreach ($hosts as $host => $prefixes) {
            $clean[strtolower((string) $host)] = array_values(array_map('strval', $prefixes));
        }
        return $this->with('wrapperHosts', $clean);
    }

    /** @param list<string> $phrases */
    public function withAlsoReadPhrases(array $phrases): self
    {
        return $this->with('alsoReadPhrases', $this->lowerList($phrases, 'alsoReadPhrases'));
    }

    /** @param list<string> $phrases */
    public function withBoilerplatePhrases(array $phrases): self
    {
        return $this->with('boilerplatePhrases', $this->lowerList($phrases, 'boilerplatePhrases'));
    }

    /** @param list<string> $labels */
    public function withSponsoredLabels(array $labels): self
    {
        return $this->with('sponsoredLabels', $this->lowerList($labels, 'sponsoredLabels'));
    }

    /**
     * Use ext-intl when it is loaded (the default). Passing false makes the
     * extractor behave as if ext-intl were missing: no NFC, no IDN, the
     * character-count word fallback for scripts without spaces.
     */
    public function withIntl(bool $use): self
    {
        return $this->with('useIntl', $use);
    }

    public function proseMinimum(): int
    {
        return $this->proseMinimum;
    }

    public function readableBar(): float
    {
        return $this->readableBar;
    }

    public function inputCap(): int
    {
        return $this->inputCap;
    }

    public function linkDensityIndex(): float
    {
        return $this->linkDensityIndex;
    }

    public function bodyAgreement(): float
    {
        return $this->bodyAgreement;
    }

    public function furnitureMinWidth(): int
    {
        return $this->furnitureMinWidth;
    }

    public function furnitureMinHeight(): int
    {
        return $this->furnitureMinHeight;
    }

    /** @return list<string> */
    public function lazyAttributes(): array
    {
        return $this->lazyAttributes;
    }

    /** @return list<string> */
    public function placeholderNames(): array
    {
        return $this->placeholderNames;
    }

    /** @return list<string> */
    public function chromeWords(): array
    {
        return $this->chromeWords;
    }

    /** @return list<string> */
    public function positiveHints(): array
    {
        return $this->positiveHints;
    }

    /** @return list<string> */
    public function negativeHints(): array
    {
        return $this->negativeHints;
    }

    /** @return array<string, string> */
    public function nonArticlePathHints(): array
    {
        return $this->nonArticlePathHints;
    }

    /** @return list<string> */
    public function agencyPatterns(): array
    {
        return $this->agencyPatterns;
    }

    /** @return list<string> */
    public function biographyPatterns(): array
    {
        return $this->biographyPatterns;
    }

    public function defaultTimezone(): string
    {
        return $this->defaultTimezone;
    }

    public function futureTolerance(): int
    {
        return $this->futureTolerance;
    }

    /** @return list<string> */
    public function trackingParams(): array
    {
        return $this->trackingParams;
    }

    /** @return list<string> */
    public function ownHosts(): array
    {
        return $this->ownHosts;
    }

    /** @return (\Closure(string): string)|null */
    public function hostNormaliser(): ?\Closure
    {
        return $this->hostNormaliser;
    }

    public function trace(): bool
    {
        return $this->trace;
    }

    public function scoringWeights(): ScoringWeights
    {
        return new ScoringWeights($this->scoringWeights);
    }

    /** @return array<string, list<string>> */
    public function wrapperHosts(): array
    {
        return $this->wrapperHosts;
    }

    /** @return list<string> */
    public function alsoReadPhrases(): array
    {
        return $this->alsoReadPhrases;
    }

    /** @return list<string> */
    public function boilerplatePhrases(): array
    {
        return $this->boilerplatePhrases;
    }

    /** @return list<string> */
    public function sponsoredLabels(): array
    {
        return $this->sponsoredLabels;
    }

    public function useIntl(): bool
    {
        return $this->useIntl;
    }

    private function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;
        return $copy;
    }

    private function positive(int $value, string $name): void
    {
        if ($value < 1) {
            throw new ConfigurationException($name . ' must be at least 1');
        }
    }

    private function unit(float $value, string $name): void
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new ConfigurationException($name . ' must be between 0 and 1');
        }
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function lowerList(array $values, string $name): array
    {
        $out = [];
        foreach ($values as $v) {
            if (!is_string($v) || trim($v) === '') {
                throw new ConfigurationException($name . ' entries must be non-empty strings');
            }
            $out[] = mb_strtolower(trim($v), 'UTF-8');
        }
        return array_values(array_unique($out));
    }
}
