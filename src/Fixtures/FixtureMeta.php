<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fixtures;

use IndianOpinion\ReadableExtract\Exception\FixtureException;

/**
 * Validates meta.json (case 80) and the reserved-host rule (case 82).
 */
final class FixtureMeta
{
    /** Hosts that are vocabulary identifiers, not sites (JSON-LD @context, Open Graph prefixes). */
    public const VOCABULARY_HOSTS = ['schema.org', 'ogp.me', 'www.w3.org', 'purl.org'];

    public const REQUIRED = ['url', 'kind', 'label', 'pattern', 'date', 'script', 'layout'];

    /**
     * @param array<string, mixed> $meta
     */
    public static function validate(string $name, array $meta): void
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($meta[$key]) || !is_string($meta[$key]) || trim($meta[$key]) === '') {
                throw new FixtureException('Fixture "' . $name . '": meta.json needs a non-empty "' . $key . '"');
            }
        }
        $host = parse_url((string) $meta['url'], PHP_URL_HOST);
        $scheme = parse_url((string) $meta['url'], PHP_URL_SCHEME);
        if (!is_string($host) || !in_array($scheme, ['http', 'https'], true) || !self::isReservedHost($host)) {
            throw new FixtureException('Fixture "' . $name . '": meta.json url must be http(s) on a reserved example host');
        }
        if (!in_array($meta['kind'], ['synthetic', 'captured'], true)) {
            throw new FixtureException('Fixture "' . $name . '": kind must be "synthetic" or "captured"');
        }
        if (!self::isDate((string) $meta['date'])) {
            throw new FixtureException('Fixture "' . $name . '": date must be YYYY-MM-DD');
        }
        if ($meta['kind'] === 'captured') {
            if (!isset($meta['termsCheckedOn']) || !is_string($meta['termsCheckedOn']) || !self::isDate($meta['termsCheckedOn'])) {
                throw new FixtureException('Fixture "' . $name . '": a captured fixture needs termsCheckedOn (YYYY-MM-DD)');
            }
            if (!isset($meta['termsNote']) || !is_string($meta['termsNote']) || trim($meta['termsNote']) === '') {
                throw new FixtureException('Fixture "' . $name . '": a captured fixture needs termsNote');
            }
        }
        if (isset($meta['covers']) && (!is_array($meta['covers']) || !array_is_list($meta['covers']))) {
            throw new FixtureException('Fixture "' . $name . '": covers must be a list of strings');
        }
    }

    /**
     * RFC 2606 and RFC 6761 names: example.com, example.net, example.org and
     * their subdomains, and the .example, .test, .invalid and .localhost TLDs.
     */
    public static function isReservedHost(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');
        foreach (['example.com', 'example.net', 'example.org'] as $base) {
            if ($host === $base || str_ends_with($host, '.' . $base)) {
                return true;
            }
        }
        foreach (['example', 'test', 'invalid', 'localhost'] as $tld) {
            if ($host === $tld || str_ends_with($host, '.' . $tld)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every absolute http(s) URL host in a page that is neither reserved nor
     * a vocabulary host.
     *
     * @return list<string>
     */
    public static function foreignHosts(string $html): array
    {
        $m = [];
        $bad = [];
        if (preg_match_all('#(?:https?:)?//([A-Za-z0-9.\-]+\.[A-Za-z]{2,})#', $html, $m) !== false) {
            foreach ($m[1] as $host) {
                $host = strtolower($host);
                if (!self::isReservedHost($host) && !in_array($host, self::VOCABULARY_HOSTS, true)) {
                    $bad[] = $host;
                }
            }
        }
        return array_values(array_unique($bad));
    }

    private static function isDate(string $s): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
