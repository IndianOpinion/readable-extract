<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * robots.txt rules, parsed per RFC 9309.
 *
 * The group for the product token is used, else the "*" group. Several
 * groups for the same agent are merged. The longest matching rule wins;
 * on a tie Allow wins. "*" matches any run of characters and a trailing
 * "$" anchors the end. /robots.txt itself is always allowed.
 */
final class RobotsTxt
{
    public const MAX_BYTES = 512000;

    /**
     * @param list<array{agents: list<string>, rules: list<array{0: bool, 1: string}>, delay: ?float}> $groups
     */
    private function __construct(private readonly array $groups, private readonly ?bool $blanket, public readonly string $status)
    {
    }

    public static function allowAll(string $status = 'allow_all'): self
    {
        return new self([], true, $status);
    }

    public static function disallowAll(string $status = 'disallow_all'): self
    {
        return new self([], false, $status);
    }

    public static function parse(string $body): self
    {
        $body = substr($body, 0, self::MAX_BYTES);
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $hash = strpos($line, '#');
            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($key === 'user-agent') {
                $agent = strtolower(trim(preg_split('#[/\s]#', $value)[0] ?? ''));
                if ($current === null || !$lastWasAgent) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }
                    $current = ['agents' => [], 'rules' => [], 'delay' => null];
                }
                if ($agent !== '') {
                    $current['agents'][] = $agent;
                }
                $lastWasAgent = true;
                continue;
            }
            if ($current === null) {
                continue;
            }
            $lastWasAgent = false;
            if ($key === 'allow' || $key === 'disallow') {
                if ($value !== '') {
                    $current['rules'][] = [$key === 'allow', self::normalisePercent($value)];
                }
            } elseif ($key === 'crawl-delay' && is_numeric($value) && (float) $value >= 0) {
                $current['delay'] = (float) $value;
            }
        }
        if ($current !== null) {
            $groups[] = $current;
        }
        return new self($groups, null, 'parsed');
    }

    public function isAllowed(string $productToken, string $pathAndQuery): bool
    {
        if ($this->blanket !== null) {
            return $this->blanket || $pathAndQuery === '/robots.txt';
        }
        if ($pathAndQuery === '/robots.txt') {
            return true;
        }
        $path = self::normalisePercent($pathAndQuery === '' ? '/' : $pathAndQuery);
        $best = -1;
        $allowed = true;
        foreach ($this->rulesFor($productToken) as [$allow, $pattern]) {
            if (!self::matches($pattern, $path)) {
                continue;
            }
            $len = strlen($pattern);
            if ($len > $best || ($len === $best && $allow)) {
                $best = $len;
                $allowed = $allow;
            }
        }
        return $allowed;
    }

    public function crawlDelay(string $productToken): ?float
    {
        $delay = null;
        foreach ($this->groupsFor($productToken) as $g) {
            if ($g['delay'] !== null) {
                $delay = max($delay ?? 0.0, $g['delay']);
            }
        }
        return $delay;
    }

    /** @return list<array{0: bool, 1: string}> */
    private function rulesFor(string $token): array
    {
        $rules = [];
        foreach ($this->groupsFor($token) as $g) {
            foreach ($g['rules'] as $r) {
                $rules[] = $r;
            }
        }
        return $rules;
    }

    /** @return list<array{agents: list<string>, rules: list<array{0: bool, 1: string}>, delay: ?float}> */
    private function groupsFor(string $token): array
    {
        $token = strtolower($token);
        $own = [];
        $star = [];
        foreach ($this->groups as $g) {
            if (in_array($token, $g['agents'], true)) {
                $own[] = $g;
            } elseif (in_array('*', $g['agents'], true)) {
                $star[] = $g;
            }
        }
        return $own !== [] ? $own : $star;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        if (!str_contains($pattern, '*')) {
            return $anchored ? $path === $pattern : str_starts_with($path, $pattern);
        }
        return self::wildcard($pattern, $path, $anchored);
    }

    /**
     * Glob match where "*" is any run of bytes, without regex backtracking:
     * the classic two-pointer walk that only ever resumes at the last star,
     * so a hostile pattern such as "/*a*a*a...*b" costs at most
     * O(pattern x path), never exponential, and never fails open on a
     * PCRE backtrack limit. Unanchored patterns are prefix matches.
     */
    private static function wildcard(string $pattern, string $path, bool $anchored): bool
    {
        $p = 0;
        $s = 0;
        $plen = strlen($pattern);
        $slen = strlen($path);
        $star = -1;
        $mark = 0;
        while (true) {
            if ($p === $plen) {
                if (!$anchored || $s === $slen) {
                    return true;
                }
            } elseif ($pattern[$p] === '*') {
                $star = $p++;
                $mark = $s;
                continue;
            } elseif ($s < $slen && $pattern[$p] === $path[$s]) {
                $p++;
                $s++;
                continue;
            }
            if ($star < 0 || $mark >= $slen) {
                return false;
            }
            $p = $star + 1;
            $s = ++$mark;
        }
    }

    private static function normalisePercent(string $s): string
    {
        $r = preg_replace_callback('/%[0-9a-fA-F]{2}/', static fn (array $m): string => strtoupper($m[0]), $s);
        return is_string($r) ? $r : $s;
    }
}
