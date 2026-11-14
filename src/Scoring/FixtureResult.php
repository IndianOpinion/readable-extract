<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

final class FixtureResult
{
    /**
     * @param array<string, FieldResult> $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly ?float $bodyF1,
    ) {
    }

    public function passed(): bool
    {
        foreach ($this->fields as $f) {
            if (!$f->pass) {
                return false;
            }
        }
        return true;
    }

    /** Share of scored fields that pass, 0 to 1. */
    public function score(): float
    {
        if ($this->fields === []) {
            return 1.0;
        }
        $n = 0;
        foreach ($this->fields as $f) {
            $n += $f->pass ? 1 : 0;
        }
        return $n / count($this->fields);
    }

    /** @return list<string> */
    public function failedFields(): array
    {
        $out = [];
        foreach ($this->fields as $name => $f) {
            if (!$f->pass) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
