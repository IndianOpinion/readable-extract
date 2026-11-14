<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

use IndianOpinion\ReadableExtract\Warning;

/**
 * Decides a page's character set and converts its bytes to UTF-8.
 *
 * Order: byte-order mark, the caller's declared charset (Content-Type),
 * <meta charset>, <meta http-equiv="Content-Type">, then UTF-8. Only the
 * first 4 KiB is scanned for meta tags.
 *
 * @internal
 */
final class Charset
{
    private const SCAN_BYTES = 4096;

    /** Labels browsers treat as windows-1252; a valid UTF-8 page declared as one of these is read as UTF-8. */
    private const LATIN = ['windows-1252', 'iso-8859-1', 'latin1', 'us-ascii', 'ascii', 'cp1252', 'iso8859-1', 'l1'];

    /**
     * mbstring names that are transfer or markup encodings, not text
     * charsets. A page that declares one would be base64-decoded, entity-
     * decoded before parsing (turning &lt;script&gt; into markup) or wiped,
     * so the declaration is ignored with unknown_charset. UTF-7 is refused
     * as browsers refuse it: it can smuggle markup past byte-level checks.
     */
    private const REFUSED = [
        'base64', 'uuencode', 'qprint', 'quoted-printable', 'html-entities', 'html', '7bit', '8bit', 'pass',
        'utf-7', 'utf7', 'utf7-imap', 'utf-7-imap', 'auto', 'wchar', 'byte2be', 'byte2le', 'byte4be', 'byte4le',
    ];

    /**
     * @return array{text: string, charset: string, source: string, warnings: list<Warning>, invalid: int}
     */
    public static function toUtf8(string $bytes, ?string $contentType): array
    {
        $warnings = [];
        $source = 'default';
        $charset = null;

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
            $charset = 'utf-8';
            $source = 'bom';
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            $bytes = substr($bytes, 2);
            $charset = 'utf-16le';
            $source = 'bom';
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = substr($bytes, 2);
            $charset = 'utf-16be';
            $source = 'bom';
        }

        if ($charset === null && $contentType !== null) {
            $declared = self::fromContentType($contentType);
            if ($declared !== null) {
                $charset = $declared;
                $source = 'header';
            }
        }
        if ($charset === null) {
            [$metaCharset, $metaSource] = self::fromMeta(substr($bytes, 0, self::SCAN_BYTES));
            if ($metaCharset !== null) {
                $charset = $metaCharset;
                $source = $metaSource;
            }
        }
        $charset ??= 'utf-8';
        $charset = self::canonical($charset);
        if (in_array($charset, self::REFUSED, true)) {
            $warnings[] = Warning::UnknownCharset;
            $charset = 'utf-8';
        } elseif (str_starts_with($source, 'meta') && preg_match('/^utf-?(16|32)/', $charset) === 1) {
            // A meta tag that could be read as ASCII cannot be UTF-16 or UTF-32 (WHATWG: read as UTF-8).
            $charset = 'utf-8';
        }

        if (
            $source !== 'bom' && in_array($charset, self::LATIN, true) && preg_match('/[\x80-\xFF]/', $bytes) === 1
            && mb_check_encoding($bytes, 'UTF-8')
        ) {
            $warnings[] = Warning::CharsetMismatch;
            $charset = 'utf-8';
            $source .= '+utf8-detected';
        }

        if ($charset !== 'utf-8') {
            $mbName = in_array($charset, self::LATIN, true) ? 'Windows-1252' : $charset;
            try {
                $converted = mb_convert_encoding($bytes, 'UTF-8', $mbName);
                if (!is_string($converted)) {
                    throw new \ValueError('conversion failed');
                }
                return [
                    'text' => $converted,
                    'charset' => $charset,
                    'source' => $source,
                    'warnings' => $warnings,
                    'invalid' => 0,
                ];
            } catch (\ValueError) {
                $warnings[] = Warning::UnknownCharset;
                $charset = 'utf-8';
            }
        }

        $invalid = 0;
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $before = substr_count($bytes, "\u{FFFD}");
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            try {
                $bytes = mb_scrub($bytes, 'UTF-8');
            } finally {
                mb_substitute_character($prev);
            }
            $invalid = max(1, substr_count($bytes, "\u{FFFD}") - $before);
            $warnings[] = Warning::InvalidEncoding;
        }
        return ['text' => $bytes, 'charset' => 'utf-8', 'source' => $source, 'warnings' => $warnings, 'invalid' => $invalid];
    }

    public static function fromContentType(string $contentType): ?string
    {
        $m = [];
        if (preg_match('/charset\s*=\s*["\']?\s*([A-Za-z0-9_:.\-]+)/i', $contentType, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private static function fromMeta(string $prefix): array
    {
        $tags = [];
        if (preg_match_all('/<meta\b[^>]*>/i', $prefix, $tags) === false) {
            return [null, 'default'];
        }
        $direct = null;
        $equiv = null;
        foreach ($tags[0] as $tag) {
            $m = [];
            if (stripos($tag, 'http-equiv') !== false) {
                if ($equiv === null && preg_match('/charset\s*=\s*["\']?\s*([A-Za-z0-9_:.\-]+)/i', $tag, $m) === 1) {
                    $equiv = strtolower($m[1]);
                }
            } elseif ($direct === null && preg_match('/\scharset\s*=\s*["\']?\s*([A-Za-z0-9_:.\-]+)/i', $tag, $m) === 1) {
                $direct = strtolower($m[1]);
            }
        }
        if ($direct !== null) {
            return [$direct, 'meta-charset'];
        }
        if ($equiv !== null) {
            return [$equiv, 'meta-http-equiv'];
        }
        return [null, 'default'];
    }

    private static function canonical(string $charset): string
    {
        $c = strtolower(trim($charset));
        return match ($c) {
            'utf8', 'unicode-1-1-utf-8', 'x-unicode20utf8' => 'utf-8',
            default => $c,
        };
    }
}
