<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

use IndianOpinion\ReadableExtract\Exception\InvalidUrlException;

/**
 * URL parsing, RFC 3986 resolution, tracking-parameter removal and host
 * normalisation. No network access.
 *
 * @internal
 */
final class Url
{
    public const MAX_LENGTH = 8192;

    /** Throws when the URL is not an absolute http(s) URL, is over 8 KB or carries userinfo. */
    public static function assertInput(string $url): void
    {
        if (strlen($url) > self::MAX_LENGTH) {
            throw new InvalidUrlException('URL is longer than 8 KB');
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            throw new InvalidUrlException('URL must be absolute: ' . self::short($url));
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidUrlException('URL scheme must be http or https: ' . self::short($url));
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new InvalidUrlException('URL must not carry userinfo');
        }
        if ($p['host'] === '' || preg_match('/[\s<>"]/', $url) === 1) {
            throw new InvalidUrlException('URL host is empty or the URL holds spaces');
        }
    }

    public static function isHttp(?string $url): bool
    {
        if ($url === null || $url === '' || strlen($url) > self::MAX_LENGTH) {
            return false;
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || $p['host'] === '') {
            return false;
        }
        $s = strtolower($p['scheme']);
        return ($s === 'http' || $s === 'https') && !isset($p['user']) && !isset($p['pass']);
    }

    public static function host(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) && $h !== '' ? strtolower($h) : null;
    }

    /**
     * Resolve a reference against an absolute base (RFC 3986 section 5.2).
     * Returns null for an empty reference or an unusable base.
     */
    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim(str_replace(["\t", "\n", "\r"], '', $ref));
        if ($ref === '') {
            return null;
        }
        $r = parse_url($ref);
        if ($r === false) {
            return null;
        }
        if (isset($r['scheme']) && preg_match('/^[a-z][a-z0-9+.\-]*:/i', $ref) === 1) {
            if (isset($r['host'])) {
                return self::build($r + ['path' => '/'], true);
            }
            return $ref;
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $t = ['scheme' => $b['scheme']];
        if (str_starts_with($ref, '//')) {
            $r2 = parse_url($b['scheme'] . ':' . $ref);
            if (!is_array($r2) || !isset($r2['host'])) {
                return null;
            }
            return self::build($r2 + ['path' => '/'], true);
        }
        $t['host'] = $b['host'];
        if (isset($b['port'])) {
            $t['port'] = $b['port'];
        }
        $refPath = $r['path'] ?? '';
        if ($refPath === '') {
            $t['path'] = $b['path'] ?? '/';
            if (isset($r['query'])) {
                $t['query'] = $r['query'];
            } elseif (isset($b['query'])) {
                $t['query'] = $b['query'];
            }
        } else {
            if (str_starts_with($refPath, '/')) {
                $t['path'] = self::removeDots($refPath);
            } else {
                $basePath = $b['path'] ?? '/';
                $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
                if ($dir === '') {
                    $dir = '/';
                }
                $t['path'] = self::removeDots($dir . $refPath);
            }
            if (isset($r['query'])) {
                $t['query'] = $r['query'];
            }
        }
        if (isset($r['fragment'])) {
            $t['fragment'] = $r['fragment'];
        }
        return self::build($t, false);
    }

    /**
     * Remove tracking parameters and the fragment. Other parameters keep
     * their order and their exact encoding.
     *
     * @param list<string> $patterns names, a trailing * is a prefix match
     */
    public static function stripTracking(string $url, array $patterns): string
    {
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $q = strpos($url, '?');
        if ($q === false) {
            return $url;
        }
        $base = substr($url, 0, $q);
        $kept = [];
        foreach (explode('&', substr($url, $q + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (!self::matchesAny($name, $patterns)) {
                $kept[] = $pair;
            }
        }
        return $kept === [] ? $base : $base . '?' . implode('&', $kept);
    }

    /**
     * Lowercase, punycode an IDN when intl is usable, strip a trailing dot
     * and mirror labels (www, amp, m, mobile) from the left while at least
     * two labels remain.
     */
    public static function normaliseHost(string $host, bool $intl = true): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($intl && preg_match('/[^\x20-\x7E]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }
        $labels = explode('.', $host);
        while (count($labels) > 2 && in_array($labels[0], ['www', 'amp', 'm', 'mobile'], true)) {
            array_shift($labels);
        }
        return implode('.', $labels);
    }

    /** True when the two normalised hosts are equal or one is a subdomain of the other. */
    public static function sameOutlet(string $a, string $b): bool
    {
        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }

    /** True when the URL is the site's home page: path empty or "/" and no query. */
    public static function isHomePage(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p)) {
            return false;
        }
        $path = $p['path'] ?? '';
        return ($path === '' || $path === '/') && !isset($p['query']);
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pat) {
            if (str_ends_with($pat, '*')) {
                if (str_starts_with($name, substr($pat, 0, -1))) {
                    return true;
                }
            } elseif ($name === $pat) {
                return true;
            }
        }
        return false;
    }

    private static function removeDots(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            if ($seg === '.') {
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $seg;
        }
        $res = implode('/', $out);
        return str_starts_with($res, '/') ? $res : '/' . $res;
    }

    /** @param array<string, int|string> $p */
    private static function build(array $p, bool $normaliseDots): string
    {
        $path = (string) ($p['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if ($normaliseDots) {
            $path = self::removeDots($path);
        }
        $s = strtolower((string) $p['scheme']) . '://' . strtolower((string) $p['host']);
        if (isset($p['port'])) {
            $s .= ':' . $p['port'];
        }
        $s .= $path;
        if (isset($p['query'])) {
            $s .= '?' . $p['query'];
        }
        if (isset($p['fragment'])) {
            $s .= '#' . $p['fragment'];
        }
        return $s;
    }

    private static function short(string $url): string
    {
        return strlen($url) > 100 ? substr($url, 0, 100) . '...' : $url;
    }
}
