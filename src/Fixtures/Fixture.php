<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fixtures;

/**
 * One saved page with its expected extraction and its provenance.
 */
final class Fixture
{
    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $name,
        public readonly string $dir,
        public readonly string $html,
        public readonly array $expected,
        public readonly array $meta,
    ) {
    }

    public function url(): string
    {
        return (string) $this->meta['url'];
    }
}
