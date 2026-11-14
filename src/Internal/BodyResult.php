<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

use IndianOpinion\ReadableExtract\Source;
use IndianOpinion\ReadableExtract\Warning;

/**
 * @internal
 */
final class BodyResult
{
    /** @var list<Warning> */
    public array $warnings = [];
    public string $html = '';
    public string $text = '';
    public Source $source = Source::None;
    public float $confidence = 0.0;
    public int $visibleLength = 0;
    public int $structuredLength = 0;
    public bool $structuredMeetsMinimum = false;
    public float $visibleLinkDensity = 0.0;
    public ?\DOMElement $container = null;
}
