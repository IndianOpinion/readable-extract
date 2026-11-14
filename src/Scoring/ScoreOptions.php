<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

use IndianOpinion\ReadableExtract\Exception\ConfigurationException;

final class ScoreOptions
{
    /**
     * @param int   $dateTolerance seconds two dates may differ and still match
     * @param float $bodyPassF1    body F1 at or above this counts as a pass
     * @param float $tolerance     absolute drop in accuracy or mean F1 that is a regression
     */
    public function __construct(
        public readonly int $dateTolerance = 60,
        public readonly float $bodyPassF1 = 0.9,
        public readonly float $tolerance = 0.01,
    ) {
        if ($dateTolerance < 0 || $bodyPassF1 < 0 || $bodyPassF1 > 1 || $tolerance < 0 || $tolerance > 1) {
            throw new ConfigurationException('Score options out of range');
        }
    }
}
