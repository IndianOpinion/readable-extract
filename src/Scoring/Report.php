<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

/**
 * Per-field accuracy, mean body F1 and the worst fixtures (case 86). The
 * three output formats are views of the same numbers.
 */
final class Report
{
    /**
     * @param list<FixtureResult> $results
     */
    public function __construct(public readonly array $results)
    {
    }

    /** @return array<string, float> field => share of fixtures where it passed */
    public function fieldAccuracy(): array
    {
        $n = [];
        $p = [];
        foreach ($this->results as $r) {
            foreach ($r->fields as $name => $f) {
                $n[$name] = ($n[$name] ?? 0) + 1;
                $p[$name] = ($p[$name] ?? 0) + ($f->pass ? 1 : 0);
            }
        }
        $out = [];
        foreach (Scorer::FIELDS as $field) {
            if (isset($n[$field])) {
                $out[$field] = round($p[$field] / $n[$field], 4);
            }
        }
        return $out;
    }

    /** @return array<string, int> field => fixtures scored on it */
    public function fieldCounts(): array
    {
        $n = [];
        foreach ($this->results as $r) {
            foreach (array_keys($r->fields) as $name) {
                $n[$name] = ($n[$name] ?? 0) + 1;
            }
        }
        return $n;
    }

    public function meanBodyF1(): float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($this->results as $r) {
            if ($r->bodyF1 !== null) {
                $sum += $r->bodyF1;
                $n++;
            }
        }
        return $n === 0 ? 1.0 : round($sum / $n, 4);
    }

    public function passedCount(): int
    {
        return count(array_filter($this->results, static fn (FixtureResult $r): bool => $r->passed()));
    }

    /** @return list<FixtureResult> */
    public function worst(int $n = 10): array
    {
        $list = $this->results;
        usort($list, static fn (FixtureResult $a, FixtureResult $b): int
            => [$a->score(), $a->bodyF1 ?? 1.0, $a->name] <=> [$b->score(), $b->bodyF1 ?? 1.0, $b->name]);
        return array_slice(array_values(array_filter($list, static fn (FixtureResult $r): bool => !$r->passed())), 0, $n);
    }

    /** @return array<string, bool> */
    public function fixturePasses(): array
    {
        $out = [];
        foreach ($this->results as $r) {
            $out[$r->name] = $r->passed();
        }
        ksort($out);
        return $out;
    }

    public function compare(Baseline $baseline, float $tolerance = 0.01): Comparison
    {
        return Comparison::of($this, $baseline, $tolerance);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $fixtures = [];
        foreach ($this->results as $r) {
            $fields = [];
            foreach ($r->fields as $name => $f) {
                $fields[$name] = ['pass' => $f->pass, 'score' => round($f->score, 4)] + $f->detail;
            }
            $fixtures[$r->name] = ['passed' => $r->passed(), 'score' => round($r->score(), 4), 'fields' => $fields];
        }
        ksort($fixtures);
        return [
            'fixtures' => count($this->results),
            'passed' => $this->passedCount(),
            'fieldAccuracy' => $this->fieldAccuracy(),
            'meanBodyF1' => $this->meanBodyF1(),
            'worst' => array_map(static fn (FixtureResult $r): array => [
                'name' => $r->name, 'score' => round($r->score(), 4), 'failed' => $r->failedFields(),
            ], $this->worst()),
            'results' => $fixtures,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    public function toText(?Comparison $comparison = null): string
    {
        $lines = [];
        $lines[] = sprintf('Fixtures: %d, passed: %d', count($this->results), $this->passedCount());
        $lines[] = '';
        $lines[] = 'Field accuracy:';
        $counts = $this->fieldCounts();
        foreach ($this->fieldAccuracy() as $field => $acc) {
            $lines[] = sprintf('  %-13s %6.4f  (%d fixtures)', $field, $acc, $counts[$field] ?? 0);
        }
        $lines[] = sprintf('Mean body F1: %.4f', $this->meanBodyF1());
        $worst = $this->worst();
        if ($worst !== []) {
            $lines[] = '';
            $lines[] = 'Worst fixtures:';
            foreach ($worst as $r) {
                $lines[] = sprintf('  %-40s %.2f  failed: %s', $r->name, $r->score(), implode(', ', $r->failedFields()));
            }
        }
        if ($comparison !== null) {
            $lines[] = '';
            $lines[] = $comparison->toText();
        }
        return implode("\n", $lines) . "\n";
    }

    public function toMarkdown(?Comparison $comparison = null): string
    {
        $lines = [];
        $lines[] = '### Extraction score';
        $lines[] = '';
        $lines[] = sprintf('%d fixtures, %d passed. Mean body F1: **%.4f**.', count($this->results), $this->passedCount(), $this->meanBodyF1());
        $lines[] = '';
        $lines[] = '| Field | Accuracy | Fixtures |';
        $lines[] = '|---|---:|---:|';
        $counts = $this->fieldCounts();
        foreach ($this->fieldAccuracy() as $field => $acc) {
            $lines[] = sprintf('| %s | %.4f | %d |', $field, $acc, $counts[$field] ?? 0);
        }
        $worst = $this->worst();
        if ($worst !== []) {
            $lines[] = '';
            $lines[] = '**Worst fixtures**';
            $lines[] = '';
            foreach ($worst as $r) {
                $lines[] = sprintf('- `%s` (%.2f): %s', $r->name, $r->score(), implode(', ', $r->failedFields()));
            }
        }
        if ($comparison !== null) {
            $lines[] = '';
            $lines[] = $comparison->toMarkdown();
        }
        return implode("\n", $lines) . "\n";
    }
}
