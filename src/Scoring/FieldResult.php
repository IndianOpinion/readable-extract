<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

final class FieldResult
{
    /**
     * @param array<string, float|int|string|bool|null> $detail
     */
    public function __construct(
        public readonly string $field,
        public readonly bool $pass,
        public readonly float $score,
        public readonly array $detail = [],
    ) {
    }
}
