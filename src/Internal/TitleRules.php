<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Case 40: strips a site-name suffix or prefix from a title.
 *
 * @internal
 */
final class TitleRules
{
    private const SUFFIX_SEPARATORS = [' | ', ' - ', " \u{2013} ", " \u{2014} ", ' :: ', " \u{00BB} ", " \u{00B7} ", ' / ', ' : '];
    private const PREFIX_SEPARATORS = [': ', ' | ', ' - ', " \u{2013} ", " \u{2014} ", ' :: ', " \u{00BB} "];

    public static function strip(string $title, ?string $siteName, ?string $host): string
    {
        $keys = self::siteKeys($siteName, $host);
        if ($keys === []) {
            return $title;
        }
        foreach (self::SUFFIX_SEPARATORS as $sep) {
            $pos = mb_strrpos($title, $sep, 0, 'UTF-8');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $remainder = mb_substr($title, 0, $pos, 'UTF-8');
            $removed = mb_substr($title, $pos + mb_strlen($sep, 'UTF-8'), null, 'UTF-8');
            if (self::matchesSite($removed, $keys)) {
                return self::keep($remainder) ? trim($remainder) : $title;
            }
        }
        foreach (self::PREFIX_SEPARATORS as $sep) {
            $pos = mb_strpos($title, $sep, 0, 'UTF-8');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $removed = mb_substr($title, 0, $pos, 'UTF-8');
            $remainder = mb_substr($title, $pos + mb_strlen($sep, 'UTF-8'), null, 'UTF-8');
            if (self::matchesSite($removed, $keys)) {
                return self::keep($remainder) ? trim($remainder) : $title;
            }
        }
        return $title;
    }

    private static function keep(string $remainder): bool
    {
        $remainder = trim($remainder);
        $words = count(preg_split('/\s+/u', $remainder) ?: []);
        return $words >= 3 && Text::length($remainder) >= 15;
    }

    /** @param list<string> $keys */
    private static function matchesSite(string $removed, array $keys): bool
    {
        $r = self::norm($removed);
        if ($r === '') {
            return false;
        }
        foreach ($keys as $k) {
            if ($r === $k || (strlen($k) >= 3 && str_starts_with($r, $k)) || (strlen($r) >= 4 && strlen($r) * 2 >= strlen($k) && str_starts_with($k, $r))) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function siteKeys(?string $siteName, ?string $host): array
    {
        $keys = [];
        if ($siteName !== null && self::norm($siteName) !== '') {
            $keys[] = self::norm($siteName);
        }
        if ($host !== null && $host !== '') {
            $h = Url::normaliseHost($host, false);
            $keys[] = self::norm($h);
            $labels = explode('.', $h);
            $n = count($labels);
            if ($n >= 2) {
                // The label before the top-level domain: "daily" in daily.example.
                $keys[] = self::norm($labels[$n - 2]);
                $keys[] = self::norm(implode('.', array_slice($labels, 0, -1)));
            }
        }
        return array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
    }

    private static function norm(string $s): string
    {
        return Text::replace('/[^\p{L}\p{N}\p{M}]+/u', '', Text::lower($s));
    }
}
