<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

/**
 * A run against the baseline. A regression is any field accuracy or the
 * mean body F1 dropping by more than the tolerance, or a fixture that
 * passed in the baseline failing now.
 */
final class Comparison
{
    /**
     * @param list<string> $regressions
     * @param list<string> $improvements
     */
    public function __construct(public readonly array $regressions, public readonly array $improvements)
    {
    }

    public static function of(Report $report, Baseline $base, float $tolerance): self
    {
        $reg = [];
        $imp = [];
        $acc = $report->fieldAccuracy();
        foreach ($base->fieldAccuracy as $field => $was) {
            $now = $acc[$field] ?? null;
            if ($now === null) {
                continue;
            }
            if ($now < $was - $tolerance - 1e-9) {
                $reg[] = sprintf('%s accuracy fell from %.4f to %.4f', $field, $was, $now);
            } elseif ($now > $was + 1e-9) {
                $imp[] = sprintf('%s accuracy rose from %.4f to %.4f', $field, $was, $now);
            }
        }
        $f1 = $report->meanBodyF1();
        if ($f1 < $base->meanBodyF1 - $tolerance - 1e-9) {
            $reg[] = sprintf('mean body F1 fell from %.4f to %.4f', $base->meanBodyF1, $f1);
        } elseif ($f1 > $base->meanBodyF1 + 1e-9) {
            $imp[] = sprintf('mean body F1 rose from %.4f to %.4f', $base->meanBodyF1, $f1);
        }
        $now = $report->fixturePasses();
        foreach ($base->fixtures as $name => $passed) {
            if (!array_key_exists($name, $now)) {
                continue;
            }
            if ($passed && !$now[$name]) {
                $reg[] = 'fixture ' . $name . ' passed in the baseline and fails now';
            } elseif (!$passed && $now[$name]) {
                $imp[] = 'fixture ' . $name . ' now passes';
            }
        }
        return new self($reg, $imp);
    }

    public function isRegression(): bool
    {
        return $this->regressions !== [];
    }

    public function toText(): string
    {
        $lines = [$this->isRegression() ? 'REGRESSION against baseline:' : 'No regression against baseline.'];
        foreach ($this->regressions as $r) {
            $lines[] = '  - ' . $r;
        }
        if ($this->improvements !== []) {
            $lines[] = 'Improvements (baseline unchanged; run "baseline --write" to record them):';
            foreach ($this->improvements as $i) {
                $lines[] = '  + ' . $i;
            }
        }
        return implode("\n", $lines);
    }

    public function toMarkdown(): string
    {
        $lines = [$this->isRegression() ? '**Regression against baseline**' : 'No regression against baseline.'];
        foreach ($this->regressions as $r) {
            $lines[] = '- ' . $r;
        }
        if ($this->improvements !== []) {
            $lines[] = '';
            $lines[] = 'Improvements (baseline unchanged):';
            foreach ($this->improvements as $i) {
                $lines[] = '- ' . $i;
            }
        }
        return implode("\n", $lines);
    }

    /** @return array{regression: bool, regressions: list<string>, improvements: list<string>} */
    public function toArray(): array
    {
        return ['regression' => $this->isRegression(), 'regressions' => $this->regressions, 'improvements' => $this->improvements];
    }
}
