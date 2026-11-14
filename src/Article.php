<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

/**
 * The result of one extraction. Immutable.
 */
final class Article implements \JsonSerializable
{
    /**
     * @param list<Author>         $authors
     * @param list<Author>         $editors
     * @param list<string>         $sponsoredSignals
     * @param list<Warning>        $warnings
     * @param array<string, Source> $fieldSources     keys: body, title, authors, publishedAt, leadImage
     * @param array<string, float>  $fieldConfidence  same keys, 0 to 1
     */
    public function __construct(
        public readonly PageType $pageType,
        public readonly ?string $title,
        public readonly string $bodyHtml,
        public readonly string $bodyText,
        public readonly int $wordCount,
        public readonly int $charCount,
        public readonly string $wordCountMethod,
        public readonly array $authors,
        public readonly array $editors,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly ?\DateTimeImmutable $modifiedAt,
        public readonly ?Image $leadImage,
        public readonly ?string $canonicalUrl,
        public readonly ?string $ampUrl,
        public readonly ?string $host,
        public readonly ?string $language,
        public readonly ?string $detectedScript,
        public readonly ?string $siteName,
        public readonly ?bool $accessibleForFree,
        public readonly array $sponsoredSignals,
        public readonly array $warnings,
        public readonly array $fieldSources,
        public readonly array $fieldConfidence,
        public readonly float $confidence,
        public readonly ?Trace $trace,
        private readonly int $proseMinimum,
        private readonly float $readableBar,
    ) {
    }

    /**
     * True when the page is an article or liveblog, the body meets the prose
     * minimum and the overall confidence is at or above the readable bar.
     * Hold anything else for a human.
     */
    public function isReadable(): bool
    {
        return ($this->pageType === PageType::Article || $this->pageType === PageType::Liveblog)
            && $this->charCount >= $this->proseMinimum
            && $this->confidence >= $this->readableBar;
    }

    public function hasWarning(Warning $warning): bool
    {
        return in_array($warning, $this->warnings, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $date = static fn (?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d\TH:i:s\Z');
        $sources = [];
        foreach ($this->fieldSources as $k => $s) {
            $sources[$k] = $s->value;
        }
        return [
            'pageType' => $this->pageType->value,
            'title' => $this->title,
            'bodyHtml' => $this->bodyHtml,
            'bodyText' => $this->bodyText,
            'wordCount' => $this->wordCount,
            'charCount' => $this->charCount,
            'wordCountMethod' => $this->wordCountMethod,
            'authors' => array_map(static fn (Author $a): array => $a->toArray(), $this->authors),
            'editors' => array_map(static fn (Author $a): array => $a->toArray(), $this->editors),
            'publishedAt' => $date($this->publishedAt),
            'modifiedAt' => $date($this->modifiedAt),
            'leadImage' => $this->leadImage?->toArray(),
            'canonicalUrl' => $this->canonicalUrl,
            'ampUrl' => $this->ampUrl,
            'host' => $this->host,
            'language' => $this->language,
            'detectedScript' => $this->detectedScript,
            'siteName' => $this->siteName,
            'accessibleForFree' => $this->accessibleForFree,
            'sponsoredSignals' => $this->sponsoredSignals,
            'warnings' => array_map(static fn (Warning $w): string => $w->value, $this->warnings),
            'fieldSources' => $sources,
            'fieldConfidence' => $this->fieldConfidence,
            'confidence' => $this->confidence,
            'readable' => $this->isReadable(),
            'trace' => $this->trace?->jsonSerialize(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
