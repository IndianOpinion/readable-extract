<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

/**
 * A lead image URL. The library never downloads it.
 */
final class Image implements \JsonSerializable
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly Source $source = Source::None,
    ) {
    }

    /** @return array{url: string, width: ?int, height: ?int, source: string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'width' => $this->width,
            'height' => $this->height,
            'source' => $this->source->value,
        ];
    }

    /** @return array{url: string, width: ?int, height: ?int, source: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
