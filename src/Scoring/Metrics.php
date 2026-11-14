<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

use IndianOpinion\ReadableExtract\Internal\Text;

/**
 * The comparison rules of case 85.
 */
final class Metrics
{
    /** Case, whitespace and punctuation insensitive. */
    public static function normaliseText(string $s): string
    {
        return Text::line(Text::replace('/[^\p{L}\p{N}\p{M}\s]+/u', ' ', Text::lower($s)));
    }

    public static function normaliseUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host'])) {
            return $url;
        }
        $path = $p['path'] ?? '/';
        return strtolower($p['scheme'] ?? 'https') . '://' . strtolower($p['host'])
            . (isset($p['port']) ? ':' . $p['port'] : '')
            . ($path === '' ? '/' : $path) . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /**
     * Set precision and recall over normalised names. Two empty sets are a
     * perfect match.
     *
     * @param list<string> $expected
     * @param list<string> $actual
     * @return array{precision: float, recall: float}
     */
    public static function setPrecisionRecall(array $expected, array $actual): array
    {
        $e = array_values(array_unique(array_map([self::class, 'normaliseText'], $expected)));
        $a = array_values(array_unique(array_map([self::class, 'normaliseText'], $actual)));
        if ($e === [] && $a === []) {
            return ['precision' => 1.0, 'recall' => 1.0];
        }
        $hit = (float) count(array_intersect($a, $e));
        return [
            'precision' => $a === [] ? 0.0 : $hit / count($a),
            'recall' => $e === [] ? 0.0 : $hit / count($e),
        ];
    }

    /**
     * Token precision, recall and F1 with multiset counts. Two empty texts
     * score 1.
     *
     * @return array{precision: float, recall: float, f1: float}
     */
    public static function tokenF1(string $expected, string $actual): array
    {
        $e = array_count_values(Text::tokens($expected));
        $a = array_count_values(Text::tokens($actual));
        $ne = array_sum($e);
        $na = array_sum($a);
        if ($ne === 0 && $na === 0) {
            return ['precision' => 1.0, 'recall' => 1.0, 'f1' => 1.0];
        }
        if ($ne === 0 || $na === 0) {
            return ['precision' => 0.0, 'recall' => 0.0, 'f1' => 0.0];
        }
        $common = 0.0;
        foreach ($a as $tok => $n) {
            $common += min($n, $e[$tok] ?? 0);
        }
        $p = $common / $na;
        $r = $common / $ne;
        return ['precision' => $p, 'recall' => $r, 'f1' => $p + $r > 0 ? 2 * $p * $r / ($p + $r) : 0.0];
    }

    public static function datesMatch(?string $expected, ?\DateTimeImmutable $actual, int $tolerance): bool
    {
        if ($expected === null || $expected === '') {
            return $actual === null;
        }
        if ($actual === null) {
            return false;
        }
        try {
            $e = new \DateTimeImmutable($expected);
        } catch (\Exception) {
            return false;
        }
        return abs($e->getTimestamp() - $actual->getTimestamp()) <= $tolerance;
    }
}
