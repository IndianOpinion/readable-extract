<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Date parsing (cases 48 to 51). Machine dates are ISO 8601 (RFC 2822 is
 * accepted too). Visible dates cover the common forms on Indian news
 * sites, English, Hindi and Marathi month names, and relative text when a
 * reference time is given. Every result is UTC.
 *
 * @internal
 */
final class Dates
{
    /** Month name => month number. Latin and Devanagari only (decision 5). */
    public const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3, 'april' => 4,
        'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
        // Hindi.
        "\u{091C}\u{0928}\u{0935}\u{0930}\u{0940}" => 1,
        "\u{092B}\u{0930}\u{0935}\u{0930}\u{0940}" => 2,
        "\u{092B}\u{093C}\u{0930}\u{0935}\u{0930}\u{0940}" => 2,
        "\u{095E}\u{0930}\u{0935}\u{0930}\u{0940}" => 2,
        "\u{092E}\u{093E}\u{0930}\u{094D}\u{091A}" => 3,
        "\u{0905}\u{092A}\u{094D}\u{0930}\u{0948}\u{0932}" => 4,
        "\u{0905}\u{092A}\u{094D}\u{0930}\u{0947}\u{0932}" => 4,
        "\u{092E}\u{0908}" => 5,
        "\u{091C}\u{0942}\u{0928}" => 6,
        "\u{091C}\u{0941}\u{0932}\u{093E}\u{0908}" => 7,
        "\u{0905}\u{0917}\u{0938}\u{094D}\u{0924}" => 8,
        "\u{0938}\u{093F}\u{0924}\u{0902}\u{092C}\u{0930}" => 9,
        "\u{0938}\u{093F}\u{0924}\u{092E}\u{094D}\u{092C}\u{0930}" => 9,
        "\u{0905}\u{0915}\u{094D}\u{091F}\u{0942}\u{092C}\u{0930}" => 10,
        "\u{0905}\u{0915}\u{094D}\u{0924}\u{0942}\u{092C}\u{0930}" => 10,
        "\u{0928}\u{0935}\u{0902}\u{092C}\u{0930}" => 11,
        "\u{0928}\u{0935}\u{092E}\u{094D}\u{092C}\u{0930}" => 11,
        "\u{0926}\u{093F}\u{0938}\u{0902}\u{092C}\u{0930}" => 12,
        "\u{0926}\u{093F}\u{0938}\u{092E}\u{094D}\u{092C}\u{0930}" => 12,
        // Marathi.
        "\u{091C}\u{093E}\u{0928}\u{0947}\u{0935}\u{093E}\u{0930}\u{0940}" => 1,
        "\u{092B}\u{0947}\u{092C}\u{094D}\u{0930}\u{0941}\u{0935}\u{093E}\u{0930}\u{0940}" => 2,
        "\u{090F}\u{092A}\u{094D}\u{0930}\u{093F}\u{0932}" => 4,
        "\u{092E}\u{0947}" => 5,
        "\u{091C}\u{0941}\u{0932}\u{0948}" => 7,
        "\u{0911}\u{0917}\u{0938}\u{094D}\u{091F}" => 8,
        "\u{0938}\u{092A}\u{094D}\u{091F}\u{0947}\u{0902}\u{092C}\u{0930}" => 9,
        "\u{0911}\u{0915}\u{094D}\u{091F}\u{094B}\u{092C}\u{0930}" => 10,
        "\u{0928}\u{094B}\u{0935}\u{094D}\u{0939}\u{0947}\u{0902}\u{092C}\u{0930}" => 11,
        "\u{0921}\u{093F}\u{0938}\u{0947}\u{0902}\u{092C}\u{0930}" => 12,
    ];

    private const MIN_YEAR = 1990;

    private \DateTimeZone $zone;
    private \DateTimeZone $utc;
    private string $monthPattern;

    public function __construct(
        string $defaultZone,
        private readonly ?\DateTimeImmutable $reference,
        private readonly int $futureTolerance,
    ) {
        $this->zone = new \DateTimeZone($defaultZone);
        $this->utc = new \DateTimeZone('UTC');
        $names = array_keys(self::MONTHS);
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->monthPattern = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));
    }

    /**
     * ISO 8601 (or RFC 2822) machine date.
     *
     * @return array{date: \DateTimeImmutable, assumed: bool}|null
     */
    public function machine(string $raw): ?array
    {
        $s = trim(Text::asciiDigits($raw));
        $m = [];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:[.,]\d+)?)?)?\s*(Z|UTC|GMT|IST|[+-]\d{2}(?::?\d{2})?)?$/i', $s, $m) === 1) {
            return $this->build(
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0,
                isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0,
                isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0,
                $m[7] ?? '',
            );
        }
        if (preg_match('/^[a-z]{3},?\s+\d{1,2}\s+[a-z]{3}\s+\d{4}\s+\d{2}:\d{2}(:\d{2})?\s+([+-]\d{4}|gmt|utc|z)$/i', $s) === 1) {
            $d = \DateTimeImmutable::createFromFormat(\DATE_RFC2822, $s) ?: \DateTimeImmutable::createFromFormat('D, d M Y H:i O', $s);
            if ($d instanceof \DateTimeImmutable) {
                return ['date' => $d->setTimezone($this->utc), 'assumed' => false];
            }
        }
        return null;
    }

    /**
     * Visible date text. A relative date returns relative=true, with a date
     * only when a reference time was given.
     *
     * @return array{date: ?\DateTimeImmutable, assumed: bool, relative: bool}|null
     */
    public function visible(string $raw): ?array
    {
        $s = Text::lower(Text::line(Text::asciiDigits($raw)));
        if ($s === '' || strlen($s) > 300) {
            return null;
        }
        $iso = [];
        if (preg_match('/\d{4}-\d{2}-\d{2}(?:[t ]\d{2}:\d{2}(?::\d{2})?(?:z|[+-]\d{2}:?\d{2})?)?/', $s, $iso) === 1) {
            $r = $this->machine($iso[0]);
            if ($r !== null) {
                return $r + ['relative' => false];
            }
        }
        $rel = $this->relative($s);
        if ($rel !== null) {
            return $rel;
        }
        $mp = $this->monthPattern;
        $lb = '(?<![\p{L}\p{M}])';
        $la = '(?![\p{L}\p{M}])';
        $m = [];
        $y = $mo = $d = null;
        $rest = $s;
        if (preg_match('/' . $lb . '(\d{1,2})(?:st|nd|rd|th)?\s*(' . $mp . ')' . $la . '\.?,?\s*(\d{4})/u', $s, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], self::MONTHS[$m[2]], (int) $m[3]];
        } elseif (preg_match('/' . $lb . '(' . $mp . ')' . $la . '\.?\s*(\d{1,2})(?:st|nd|rd|th)?,?\s*(\d{4})/u', $s, $m) === 1) {
            [$mo, $d, $y] = [self::MONTHS[$m[1]], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(?<!\d)(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})(?!\d)/', $s, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(?<!\d)(\d{4})[\/.](\d{1,2})[\/.](\d{1,2})(?!\d)/', $s, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        $rest = str_replace($m[0], ' ', $s);
        $h = $i = $sec = 0;
        $zone = '';
        $t = [];
        if (preg_match('/(?<!\d)(\d{1,2})[:.](\d{2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)?(?![\p{L}\d])/u', $rest, $t) === 1) {
            [$h, $i] = [(int) $t[1], (int) $t[2]];
            $sec = isset($t[3]) && $t[3] !== '' ? (int) $t[3] : 0;
            $ampm = isset($t[4]) ? str_replace('.', '', $t[4]) : '';
            if ($ampm === 'pm' && $h < 12) {
                $h += 12;
            } elseif ($ampm === 'am' && $h === 12) {
                $h = 0;
            }
        }
        $z = [];
        if (preg_match('/(?<![\p{L}])(ist|gmt|utc|z)(?![\p{L}])|(?<![\d:])([+-]\d{2}:?\d{2})(?!\d)/u', $rest, $z) === 1) {
            $zone = $z[1] !== '' ? $z[1] : ($z[2] ?? '');
        }
        $r = $this->build($y, $mo, $d, $h, $i, $sec, $zone);
        return $r === null ? null : $r + ['relative' => false];
    }

    public function isPlausible(\DateTimeImmutable $d): bool
    {
        $now = $this->reference ?? new \DateTimeImmutable('now', $this->utc);
        if ($d->getTimestamp() > $now->getTimestamp() + $this->futureTolerance) {
            return false;
        }
        return (int) $d->format('Y') >= self::MIN_YEAR;
    }

    /**
     * @return array{date: ?\DateTimeImmutable, assumed: bool, relative: bool}|null
     */
    private function relative(string $s): ?array
    {
        $units = [
            'second' => 1, 'sec' => 1, 'minute' => 60, 'min' => 60, 'hour' => 3600, 'hr' => 3600, 'day' => 86400,
            'week' => 604800,
            "\u{0938}\u{0947}\u{0915}\u{0902}\u{0921}" => 1,
            "\u{092E}\u{093F}\u{0928}\u{091F}" => 60,
            "\u{0918}\u{0902}\u{091F}\u{0947}" => 3600,
            "\u{0918}\u{0902}\u{091F}\u{093E}" => 3600,
            "\u{0926}\u{093F}\u{0928}" => 86400,
        ];
        $seconds = null;
        $m = [];
        if (preg_match('/(?<!\d)(\d{1,3}|an?|one)\s+(second|sec|minute|min|hour|hr|day|week)s?\s+ago(?!\p{L})/u', $s, $m) === 1) {
            $n = ctype_digit($m[1]) ? (int) $m[1] : 1;
            $seconds = $n * $units[$m[2]];
        } elseif (preg_match('/(?<!\d)(\d{1,3})\s+(' . implode('|', array_slice(array_keys($units), 8)) . ')\s+\x{092A}\x{0939}\x{0932}\x{0947}/u', $s, $m) === 1) {
            $seconds = (int) $m[1] * $units[$m[2]];
        } elseif (preg_match('/(?<!\p{L})(just now|moments ago)(?!\p{L})/u', $s) === 1) {
            $seconds = 0;
        } elseif (preg_match('/(?<!\p{L})yesterday(?!\p{L})/u', $s) === 1) {
            $seconds = 86400;
        } else {
            return null;
        }
        if ($this->reference === null) {
            return ['date' => null, 'assumed' => false, 'relative' => true];
        }
        $d = $this->reference->setTimezone($this->utc)->modify('-' . $seconds . ' seconds');
        return ['date' => $d, 'assumed' => false, 'relative' => true];
    }

    /**
     * @return array{date: \DateTimeImmutable, assumed: bool}|null
     */
    private function build(int $y, int $mo, int $d, int $h, int $i, int $s, string $zone): ?array
    {
        if (!checkdate($mo, $d, $y) || $h > 23 || $i > 59 || $s > 60) {
            return null;
        }
        $zone = strtolower(trim($zone));
        $assumed = false;
        if ($zone === '') {
            $tz = $this->zone;
            $assumed = true;
        } elseif ($zone === 'z' || $zone === 'utc' || $zone === 'gmt') {
            $tz = $this->utc;
        } elseif ($zone === 'ist') {
            $tz = new \DateTimeZone('+05:30');
        } else {
            $digits = str_replace(':', '', $zone);
            if (strlen($digits) === 3) {
                $digits .= '00';
            }
            try {
                $tz = new \DateTimeZone(substr($digits, 0, 3) . ':' . substr($digits, 3, 2));
            } catch (\Exception) {
                return null;
            }
        }
        $dt = (new \DateTimeImmutable('now', $tz))->setDate($y, $mo, $d)->setTime($h, $i, min($s, 59));
        return ['date' => $dt->setTimezone($this->utc), 'assumed' => $assumed];
    }
}
