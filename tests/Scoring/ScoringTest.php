<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Scoring;

use IndianOpinion\ReadableExtract\Cli\Application;
use IndianOpinion\ReadableExtract\Exception\FixtureException;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\Fixtures\Fixture;
use IndianOpinion\ReadableExtract\Fixtures\Suite;
use IndianOpinion\ReadableExtract\Scoring\Baseline;
use IndianOpinion\ReadableExtract\Scoring\Metrics;
use IndianOpinion\ReadableExtract\Scoring\Scorer;
use IndianOpinion\ReadableExtract\Tests\Support\NoNetwork;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use PHPUnit\Framework\TestCase;

/** PRD cases 79, 81 and 85 to 88. */
final class ScoringTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/re-scoring-' . bin2hex(random_bytes(6));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
            }
            rmdir($this->tmp);
        }
    }

    /** @param array<string, mixed> $expected */
    private function fixture(string $name, string $html, array $expected): void
    {
        $dir = $this->tmp . '/' . $name;
        mkdir($dir);
        file_put_contents($dir . '/page.html', $html);
        file_put_contents($dir . '/expected.json', json_encode($expected));
        file_put_contents($dir . '/meta.json', json_encode([
            'url' => 'https://www.daily.example/' . $name, 'kind' => 'synthetic', 'label' => 'test daily, English',
            'pattern' => 'test', 'date' => '2026-11-14', 'script' => 'Latn', 'layout' => 'article',
        ]));
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function cli(string ...$argv): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application($out, $err))->run(array_values($argv));
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testCase79MissingBodyTextScoresOtherFieldsOnly(): void
    {
        $this->fixture('no-body', Page::article(Page::prose()), ['pageType' => 'article', 'title' => 'Council asks for a timeline']);
        $report = (new Scorer(new Extractor()))->score(Suite::load($this->tmp));
        $r = $report->results[0];
        self::assertSame(['pageType', 'title'], array_keys($r->fields));
        self::assertNull($r->bodyF1);
        self::assertArrayNotHasKey('body', $report->fieldAccuracy());
    }

    public function testCase81ScorerRunsOfflineEvenWhenTheNetworkThrows(): void
    {
        NoNetwork::install();
        try {
            $report = (new Scorer(new Extractor()))->score(Suite::load(__DIR__ . '/../../fixtures'));
        } finally {
            NoNetwork::uninstall();
        }
        self::assertSame([], NoNetwork::$attempts);
        self::assertGreaterThanOrEqual(40, count($report->results));
    }

    public function testCase85MetricsOnHandBuiltCases(): void
    {
        $pr = Metrics::setPrecisionRecall(['Asha Rao', 'Vikram Sen'], ['asha rao', 'Someone Else', 'Third']);
        self::assertEqualsWithDelta(1 / 3, $pr['precision'], 1e-9);
        self::assertEqualsWithDelta(0.5, $pr['recall'], 1e-9);
        self::assertSame(['precision' => 1.0, 'recall' => 1.0], Metrics::setPrecisionRecall([], []));

        $f = Metrics::tokenF1('a b c d', 'a b x');
        self::assertEqualsWithDelta(2 / 3, $f['precision'], 1e-9);
        self::assertEqualsWithDelta(0.5, $f['recall'], 1e-9);
        self::assertEqualsWithDelta(4 / 7, $f['f1'], 1e-9);
        self::assertSame(1.0, Metrics::tokenF1('', '')['f1']);
        self::assertSame(0.0, Metrics::tokenF1('a', '')['f1']);

        $d = new \DateTimeImmutable('2026-11-14T05:00:59Z');
        self::assertTrue(Metrics::datesMatch('2026-11-14T05:00:00Z', $d, 60));
        self::assertFalse(Metrics::datesMatch('2026-11-14T04:59:00Z', $d, 60));
        self::assertTrue(Metrics::datesMatch(null, null, 60));
        self::assertFalse(Metrics::datesMatch(null, $d, 60));

        self::assertSame(Metrics::normaliseText('Council, asks!  For a TIMELINE.'), Metrics::normaliseText('council asks for a timeline'));
        self::assertSame('https://img.daily.example/a.jpg', Metrics::normaliseUrl('HTTPS://IMG.Daily.example/a.jpg#x'));
    }

    public function testCase85MustContainAndMustNotContain(): void
    {
        $scorer = new Scorer(new Extractor());
        $html = Page::article(Page::prose());
        $f = new Fixture('x', $this->tmp, $html, ['bodyMustContain' => ['drainage plan'], 'bodyMustNotContain' => ['cricket']], ['url' => Page::URL]);
        self::assertTrue($scorer->scoreOne($f, (new Extractor())->extract($html, Page::URL))->fields['body']->pass);
        $g = new Fixture('y', $this->tmp, $html, ['bodyMustNotContain' => ['drainage plan']], ['url' => Page::URL]);
        self::assertFalse($scorer->scoreOne($g, (new Extractor())->extract($html, Page::URL))->fields['body']->pass);
    }

    public function testCase86AllFormatsHoldTheSameNumbers(): void
    {
        $report = (new Scorer(new Extractor()))->score(Suite::load(__DIR__ . '/../../fixtures'));
        $json = json_decode($report->toJson(), true);
        self::assertIsArray($json);
        $text = $report->toText();
        $md = $report->toMarkdown();
        foreach ($report->fieldAccuracy() as $field => $acc) {
            self::assertSame($acc, $json['fieldAccuracy'][$field]);
            self::assertStringContainsString(sprintf('%-13s %6.4f', $field, $acc), $text);
            self::assertStringContainsString(sprintf('| %s | %.4f |', $field, $acc), $md);
        }
        self::assertSame($report->meanBodyF1(), $json['meanBodyF1']);
        self::assertStringContainsString(sprintf('Mean body F1: %.4f', $report->meanBodyF1()), $text);
        self::assertStringContainsString(sprintf('Mean body F1: **%.4f**', $report->meanBodyF1()), $md);
    }

    public function testCase87RegressionExitsOneImprovementExitsZeroBaselineUnchanged(): void
    {
        $this->fixture('good', Page::article(Page::prose()), ['pageType' => 'article', 'title' => 'Council asks for a timeline']);
        $this->fixture('other', Page::article(Page::prose(900, 1)), ['pageType' => 'article', 'title' => 'Wrong title on purpose']);
        [$code] = $this->cli('baseline', '--write', $this->tmp);
        self::assertSame(0, $code);
        $baselineBytes = (string) file_get_contents($this->tmp . '/baseline.json');
        [$code] = $this->cli('score', $this->tmp);
        self::assertSame(0, $code);

        // Regression: the passing fixture now expects something else.
        file_put_contents($this->tmp . '/good/expected.json', json_encode(['pageType' => 'article', 'title' => 'Now wrong']));
        [$code, $out] = $this->cli('score', $this->tmp, '--format=markdown');
        self::assertSame(1, $code);
        self::assertStringContainsString('fixture good passed in the baseline and fails now', $out);

        // Improvement: restore it and fix the failing one.
        file_put_contents($this->tmp . '/good/expected.json', json_encode(['pageType' => 'article', 'title' => 'Council asks for a timeline']));
        file_put_contents($this->tmp . '/other/expected.json', json_encode(['pageType' => 'article', 'title' => 'Council asks for a timeline']));
        [$code, $out] = $this->cli('score', $this->tmp);
        self::assertSame(0, $code);
        self::assertStringContainsString('fixture other now passes', $out);
        self::assertSame($baselineBytes, file_get_contents($this->tmp . '/baseline.json'));
        self::assertEquals(Baseline::fromFile($this->tmp . '/baseline.json')->fixtures, ['good' => true, 'other' => false]);
    }

    public function testCase87ToleranceAppliesToAccuracyDrops(): void
    {
        $report = (new Scorer(new Extractor()))->score(Suite::load(__DIR__ . '/../../fixtures'));
        $base = Baseline::fromReport($report);
        $higher = new Baseline(array_map(static fn (float $v): float => min(1.0, $v + 0.005), $base->fieldAccuracy), $base->meanBodyF1, []);
        self::assertFalse($report->compare($higher, 0.01)->isRegression(), 'drop inside tolerance');
        $muchHigher = new Baseline(['title' => 1.5], $base->meanBodyF1, []);
        self::assertTrue($report->compare($muchHigher, 0.01)->isRegression());
    }

    public function testCase88InvalidJsonExitsTwoAndNamesTheFixture(): void
    {
        $this->fixture('broken-json', Page::article(Page::prose()), []);
        file_put_contents($this->tmp . '/broken-json/expected.json', '{"title": ');
        [$code, , $err] = $this->cli('score', $this->tmp);
        self::assertSame(2, $code);
        self::assertStringContainsString('broken-json', $err);

        unlink($this->tmp . '/broken-json/expected.json');
        try {
            Suite::load($this->tmp);
            self::fail('missing file must fail');
        } catch (FixtureException $e) {
            self::assertStringContainsString('"broken-json" is missing expected.json', $e->getMessage());
        }
    }

    public function testCliExtractAndFixtureSkeleton(): void
    {
        $file = $this->tmp . '/page.html';
        file_put_contents($file, Page::article(Page::prose()));
        [$code, $out] = $this->cli('extract', $file, '--url=' . Page::URL, '--json');
        self::assertSame(0, $code);
        $data = json_decode($out, true);
        self::assertIsArray($data);
        self::assertSame('article', $data['pageType']);
        [$code, $out] = $this->cli('extract', $file, '--url=' . Page::URL, '--trace');
        self::assertSame(0, $code);
        self::assertStringContainsString('--- trace ---', $out);
        [$code] = $this->cli('extract', $file);
        self::assertSame(2, $code);
        [$code] = $this->cli('fixture', '--new', 'new-case', '--dir=' . $this->tmp);
        self::assertSame(0, $code);
        $meta = json_decode((string) file_get_contents($this->tmp . '/new-case/meta.json'), true);
        self::assertIsArray($meta);
        self::assertArrayHasKey('termsCheckedOn', $meta);
        self::assertArrayHasKey('termsNote', $meta);
        [$code] = $this->cli('nonsense');
        self::assertSame(2, $code);
    }
}
