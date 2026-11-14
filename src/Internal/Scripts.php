<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Dominant writing system of a text, as an ISO 15924 code.
 *
 * @internal
 */
final class Scripts
{
    public const INDIC = ['Deva', 'Beng', 'Guru', 'Gujr', 'Orya', 'Taml', 'Telu', 'Knda', 'Mlym', 'Arab', 'Olck', 'Sinh'];

    private const CLASSES = [
        'Latn' => '\p{Latin}',
        'Deva' => '\p{Devanagari}',
        'Beng' => '\p{Bengali}',
        'Guru' => '\p{Gurmukhi}',
        'Gujr' => '\p{Gujarati}',
        'Orya' => '\p{Oriya}',
        'Taml' => '\p{Tamil}',
        'Telu' => '\p{Telugu}',
        'Knda' => '\p{Kannada}',
        'Mlym' => '\p{Malayalam}',
        'Arab' => '\p{Arabic}',
        'Olck' => '\p{Ol_Chiki}',
        'Sinh' => '\p{Sinhala}',
        'Hani' => '\p{Han}',
        'Thai' => '\p{Thai}',
        'Cyrl' => '\p{Cyrillic}',
    ];

    /**
     * @return array{0: ?string, 1: float} script code and its share of letters
     */
    public static function dominant(string $text): array
    {
        $sample = mb_substr($text, 0, 20000, 'UTF-8');
        $letters = preg_match_all('/[\p{L}\p{M}]/u', $sample);
        if (!is_int($letters) || $letters === 0) {
            return [null, 0.0];
        }
        $best = null;
        $bestCount = 0;
        foreach (self::CLASSES as $code => $class) {
            $n = preg_match_all('/' . $class . '/u', $sample);
            if (is_int($n) && $n > $bestCount) {
                $best = $code;
                $bestCount = $n;
            }
        }
        return [$best, min(1.0, $bestCount / $letters)];
    }
}
