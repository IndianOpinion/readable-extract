<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

use IndianOpinion\ReadableExtract\Exception\ConfigurationException;

/**
 * Every number the body scorer and the confidence model use, in one place.
 * Override any of them with ExtractorOptions::withScoringWeights().
 * docs/ARCHITECTURE.md explains each one.
 */
final class ScoringWeights
{
    public const DEFAULTS = [
        // Paragraph scoring.
        'paragraphMinChars' => 25.0,   // shorter text blocks score nothing
        'paragraphBase' => 1.0,        // every qualifying paragraph
        'commaWeight' => 1.0,          // per comma (, U+060C U+3001 U+FF0C)
        'sentenceWeight' => 0.5,       // per sentence mark (. ? ! danda, double danda)
        'lengthStep' => 100.0,         // one point per this many characters
        'lengthCap' => 3.0,            // at most this many length points
        'grandparentShare' => 0.5,     // share of a paragraph's score given to the grandparent
        // Container weights.
        'hintWeight' => 25.0,          // per positive or negative class or id hint
        'tagArticle' => 10.0,
        'tagBlock' => 5.0,             // div, section, main
        'tagMinor' => 3.0,             // pre, td, blockquote
        'tagList' => -3.0,             // ul, ol, li, dl
        'tagHeading' => -5.0,          // h1 to h6, th
        // Sibling join.
        'siblingRatio' => 0.2,         // sibling score at least this share of the top score
        'siblingMin' => 10.0,          // and at least this absolute score
        'siblingParagraphMinChars' => 80.0,
        'siblingParagraphMaxLinkDensity' => 0.25,
        // Confidence model (see ARCHITECTURE.md, decision 6).
        'confVisibleAgreed' => 1.0,    // visible body confirmed by a structured body
        'confVisible' => 0.9,          // visible body, nothing to confirm it
        'confStructured' => 0.8,       // structured body used
        'confShortMax' => 0.4,         // ceiling for a body under the prose minimum
        'factorBodyDisagreement' => 0.6,
        'factorVisibleBodyShort' => 0.85,
        'factorBiographyOnly' => 0.0,
        'factorNotAnArticle' => 0.0,
        'factorNoTypeSignal' => 0.8,   // article inferred from the body alone
    ];

    /** @var array<string, float> */
    private array $values;

    /**
     * @param array<string, int|float> $overrides
     */
    public function __construct(array $overrides = [])
    {
        $values = self::DEFAULTS;
        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                throw new ConfigurationException('Unknown scoring weight: ' . $key);
            }
            if (!is_finite((float) $value)) {
                throw new ConfigurationException('Scoring weight must be finite: ' . $key);
            }
            $values[$key] = (float) $value;
        }
        $this->values = $values;
    }

    public function get(string $key): float
    {
        if (!array_key_exists($key, $this->values)) {
            throw new ConfigurationException('Unknown scoring weight: ' . $key);
        }
        return $this->values[$key];
    }

    /** @return array<string, float> */
    public function all(): array
    {
        return $this->values;
    }
}
