<?php

/*
 * Score the bundled fixtures and compare with the committed baseline.
 * The same as `bin/readable-extract score fixtures`.
 *
 *     php examples/score-fixtures.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\Fixtures\Suite;
use IndianOpinion\ReadableExtract\Scoring\Baseline;
use IndianOpinion\ReadableExtract\Scoring\Scorer;

$dir = __DIR__ . '/../fixtures';
$report = (new Scorer(new Extractor()))->score(Suite::load($dir));
$comparison = $report->compare(Baseline::fromFile($dir . '/baseline.json'));
echo $report->toText($comparison);
exit($comparison->isRegression() ? 1 : 0);
