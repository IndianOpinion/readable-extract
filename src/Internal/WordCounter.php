<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Word counts and tokens across scripts (decision 3 in ARCHITECTURE.md).
 *
 * Scripts that put spaces between words are split on whitespace and
 * punctuation. Scripts that do not (Han, Kana, Thai, Lao, Khmer, Myanmar)
 * use IntlBreakIterator when ext-intl is loaded; otherwise every letter of
 * those scripts counts as one word ("characters" method).
 *
 * @internal
 */
final class WordCounter
{
    public const WHITESPACE = 'whitespace';
    public const INTL = 'intl';
    public const CHARACTERS = 'characters';

    private const NO_SPACE = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

    public static function needsBreaker(string $text): bool
    {
        if (preg_match(self::NO_SPACE, $text) !== 1) {
            return false;
        }
        $letters = preg_match_all('/\p{L}/u', $text);
        $nospace = preg_match_all(self::NO_SPACE, $text);
        return is_int($letters) && is_int($nospace) && $letters > 0 && $nospace * 2 >= $letters;
    }

    public static function method(string $text, bool $intl): string
    {
        if (!self::needsBreaker($text)) {
            return self::WHITESPACE;
        }
        return $intl && class_exists(\IntlBreakIterator::class) ? self::INTL : self::CHARACTERS;
    }

    /**
     * @return array{0: int, 1: string} word count and the method used
     */
    public static function count(string $text, bool $intl): array
    {
        $method = self::method($text, $intl);
        return [count(self::tokensBy($text, $method)), $method];
    }

    /** @return list<string> */
    public static function tokens(string $text, bool $intl): array
    {
        return self::tokensBy($text, self::method($text, $intl));
    }

    /** @return list<string> */
    private static function tokensBy(string $text, string $method): array
    {
        if ($method === self::WHITESPACE) {
            $parts = Text::split('/[^\p{L}\p{M}\p{N}\x{200C}\x{200D}]+/u', $text);
            return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        }
        if ($method === self::INTL) {
            $it = \IntlBreakIterator::createWordInstance('');
            if ($it instanceof \IntlRuleBasedBreakIterator) {
                $it->setText($text);
                $out = [];
                $prev = 0;
                foreach ($it as $boundary) {
                    if ($boundary === 0) {
                        continue;
                    }
                    $status = $it->getRuleStatus();
                    $piece = substr($text, $prev, $boundary - $prev);
                    $prev = $boundary;
                    if ($status !== \IntlBreakIterator::WORD_NONE && preg_match('/[\p{L}\p{N}]/u', $piece) === 1) {
                        $out[] = $piece;
                    }
                }
                return $out;
            }
        }
        // Character fallback: each letter of a no-space script is a token,
        // runs of other letters are split on non-letters.
        $out = [];
        $m = [];
        $ns = substr(self::NO_SPACE, 1, -2);
        if (preg_match_all('/' . $ns . '|(?:(?!' . $ns . ')[\p{L}\p{M}\p{N}])+/u', $text, $m) !== false) {
            foreach ($m[0] as $t) {
                $out[] = $t;
            }
        }
        return $out;
    }
}
