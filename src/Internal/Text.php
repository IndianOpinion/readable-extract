<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Small text helpers. Every regular expression here runs on one field or
 * one text node, never on a whole page, and every failure returns the
 * input unchanged so no content is lost.
 *
 * @internal
 */
final class Text
{
    /** Zero-width, bidi and control characters to remove. ZWJ (U+200D) and ZWNJ (U+200C) are kept. */
    private const STRIP = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
        . '\x{00AD}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    private const SPACES = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    /** Remove invisible characters, keeping ZWJ and ZWNJ. Whitespace is untouched. */
    public static function strip(string $s): string
    {
        return self::replace(self::STRIP, '', $s);
    }

    /** Strip invisible characters and collapse all whitespace runs to one space, trimmed. */
    public static function line(string $s): string
    {
        return trim(self::replace(self::SPACES, ' ', self::strip($s)));
    }

    /**
     * Like line(), but keeps paragraph breaks: runs that hold two or more
     * newlines become one blank line; other whitespace becomes a space.
     */
    public static function block(string $s): string
    {
        $s = self::strip($s);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $paras = self::split('/\n[ \t\x{00A0}]*\n\s*/u', $s);
        $out = [];
        foreach ($paras as $p) {
            $p = self::line($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return implode("\n\n", $out);
    }

    public static function length(string $s): int
    {
        return mb_strlen($s, 'UTF-8');
    }

    /** Characters after whitespace collapse: the unit of every length test. */
    public static function proseLength(string $s): int
    {
        return self::length(self::line($s));
    }

    public static function decodeEntities(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /** NFC when intl is usable; otherwise the input. */
    public static function nfc(string $s, bool $intl): string
    {
        if ($intl && class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) {
                return $n;
            }
        }
        return $s;
    }

    /** Convert digits of Indic and Arabic scripts to ASCII. */
    public static function asciiDigits(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            // Devanagari, Bengali, Gurmukhi, Gujarati, Oriya, Tamil, Telugu, Kannada, Malayalam.
            foreach ([0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0660, 0x06F0] as $zero) {
                for ($i = 0; $i < 10; $i++) {
                    $map[mb_chr($zero + $i, 'UTF-8')] = (string) $i;
                }
            }
        }
        return strtr($s, $map);
    }

    /** preg_replace that returns the subject unchanged on any engine failure. */
    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        $r = preg_replace($pattern, $replacement, $subject);
        return is_string($r) ? $r : $subject;
    }

    /**
     * preg_split that returns the whole subject as one part on failure.
     *
     * @return list<string>
     */
    public static function split(string $pattern, string $subject): array
    {
        $r = preg_split($pattern, $subject);
        return is_array($r) ? $r : [$subject];
    }

    /**
     * Lowercased word tokens for overlap and F1. Letters, marks and digits
     * count; punctuation splits. Scripts without spaces are split by
     * WordCounter.
     *
     * @return list<string>
     */
    public static function tokens(string $s, bool $intl = true): array
    {
        return WordCounter::tokens(self::lower(self::line($s)), $intl);
    }

    /**
     * Whole-phrase match: $phrase occurs in $text with no letter or digit
     * directly before or after it.
     */
    public static function containsPhrase(string $text, string $phrase): bool
    {
        $text = self::lower($text);
        $phrase = self::lower($phrase);
        $offset = 0;
        $tl = strlen($text);
        $pl = strlen($phrase);
        if ($pl === 0) {
            return false;
        }
        while (($pos = strpos($text, $phrase, $offset)) !== false) {
            $before = $pos === 0 ? '' : self::lastChar(substr($text, 0, $pos));
            $after = $pos + $pl >= $tl ? '' : mb_substr(substr($text, $pos + $pl), 0, 1, 'UTF-8');
            if (!self::isWordChar($before) && !self::isWordChar($after)) {
                return true;
            }
            $offset = $pos + 1;
        }
        return false;
    }

    private static function lastChar(string $s): string
    {
        return mb_substr($s, -1, 1, 'UTF-8');
    }

    private static function isWordChar(string $c): bool
    {
        return $c !== '' && preg_match('/^[\p{L}\p{N}\p{M}]$/u', $c) === 1;
    }
}
