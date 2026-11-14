<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

use IndianOpinion\ReadableExtract\Exception\FixtureException;

/**
 * The committed scores a run is compared against (case 87). Written only
 * by an explicit command.
 */
final class Baseline
{
    /**
     * @param array<string, float> $fieldAccuracy
     * @param array<string, bool>  $fixtures
     */
    public function __construct(
        public readonly array $fieldAccuracy,
        public readonly float $meanBodyF1,
        public readonly array $fixtures,
    ) {
    }

    public static function fromReport(Report $r): self
    {
        return new self($r->fieldAccuracy(), $r->meanBodyF1(), $r->fixturePasses());
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new FixtureException('Baseline file not found: ' . $file);
        }
        try {
            $d = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FixtureException('Baseline file is not valid JSON: ' . $file, 0, $e);
        }
        if (!is_array($d) || !is_array($d['fieldAccuracy'] ?? null) || !is_array($d['fixtures'] ?? null) || !is_numeric($d['meanBodyF1'] ?? null)) {
            throw new FixtureException('Baseline file has the wrong shape: ' . $file);
        }
        $acc = [];
        foreach ($d['fieldAccuracy'] as $k => $v) {
            $acc[(string) $k] = (float) $v;
        }
        $fx = [];
        foreach ($d['fixtures'] as $k => $v) {
            $fx[(string) $k] = (bool) $v;
        }
        return new self($acc, (float) $d['meanBodyF1'], $fx);
    }

    public function toJson(): string
    {
        return json_encode([
            'fieldAccuracy' => $this->fieldAccuracy,
            'meanBodyF1' => $this->meanBodyF1,
            'fixtures' => $this->fixtures,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }
}
