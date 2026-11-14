<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Fixtures;

use IndianOpinion\ReadableExtract\Exception\FixtureException;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\Fixtures\FixtureMeta;
use IndianOpinion\ReadableExtract\Fixtures\Suite;
use IndianOpinion\ReadableExtract\PageType;
use IndianOpinion\ReadableExtract\Scoring\Baseline;
use IndianOpinion\ReadableExtract\Scoring\Scorer;
use PHPUnit\Framework\TestCase;

/** PRD cases 14 (per fixture), 80, 82, 83 and 84, plus the committed baseline. */
final class FixtureSuiteTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures';
    private const COVERAGE = __DIR__ . '/../../docs/fixture-coverage.json';

    /** Tags every release must cover (case 84). */
    public const REQUIRED = [
        'type:article', 'type:liveblog', 'type:video', 'type:gallery', 'type:index', 'type:paywalled',
        'type:script_rendered', 'type:empty', 'type:unknown',
        'body:structured-wins', 'body:biography-first', 'body:biography-only', 'body:disagreement', 'body:microdata',
        'body:cleanup', 'body:danda',
        'img:lazy', 'img:srcset', 'img:noscript', 'img:placeholder', 'img:placeholder-whole-name', 'img:furniture-attr',
        'img:furniture-url', 'img:chrome', 'img:og-spacer', 'img:none', 'img:first-body',
        'date:visible-dmy-ist', 'date:visible-mdy', 'date:visible-slash', 'date:visible-dash', 'date:hindi-month',
        'date:relative', 'date:implausible', 'date:none', 'date:time-element', 'date:meta',
        'byline:agency', 'byline:editor', 'byline:hindi', 'url:amp', 'url:canonical-home',
        'lang:hi', 'lang:ta', 'lang:bn', 'lang:mr', 'lang:ml', 'lang:te', 'lang:kn', 'lang:gu', 'lang:pa', 'lang:ur',
        'charset:windows-1252', 'charset:mismatch', 'size:over-1mb',
    ];

    private static ?Suite $suite = null;

    private static function suite(): Suite
    {
        return self::$suite ??= Suite::load(self::DIR);
    }

    public function testCase14EveryPageTypeHasACorrectlyClassifiedFixture(): void
    {
        $seen = [];
        $e = new Extractor();
        foreach (self::suite()->fixtures as $f) {
            $expected = $f->expected['pageType'] ?? null;
            if (!is_string($expected)) {
                continue;
            }
            $actual = $e->extract($f->html, $f->url())->pageType->value;
            self::assertSame($expected, $actual, $f->name);
            $seen[$expected] = true;
        }
        foreach (PageType::cases() as $type) {
            if ($type === PageType::Wrapper) {
                continue; // A wrapper is known from its URL alone; fixtures use reserved hosts only. See InputTest case 5.
            }
            self::assertArrayHasKey($type->value, $seen, 'no fixture for page type ' . $type->value);
        }
    }

    public function testCase80CapturedMetaNeedsTermsAndReservedHost(): void
    {
        $base = ['url' => 'https://www.daily.example/a', 'kind' => 'captured', 'label' => 'regional daily, Hindi', 'pattern' => 'p', 'date' => '2026-11-14', 'script' => 'Deva', 'layout' => 'article'];
        foreach ([$base, $base + ['termsCheckedOn' => '2026-11-01'], $base + ['termsNote' => 'allows research copies']] as $i => $meta) {
            try {
                FixtureMeta::validate('captured-' . $i, $meta);
                self::fail('captured fixture without both terms fields passed');
            } catch (FixtureException $e) {
                self::assertStringContainsString('captured-' . $i, $e->getMessage());
            }
        }
        FixtureMeta::validate('ok', $base + ['termsCheckedOn' => '2026-11-01', 'termsNote' => 'allows research copies']);

        $this->expectException(FixtureException::class);
        FixtureMeta::validate('real-host', ['url' => 'https://www.some-real-outlet.in/a', 'kind' => 'synthetic'] + $base);
    }

    public function testCase80EveryFixtureMetaIsValid(): void
    {
        self::assertGreaterThan(0, self::suite()->count());
        foreach (self::suite()->fixtures as $f) {
            self::assertTrue(FixtureMeta::isReservedHost((string) parse_url($f->url(), PHP_URL_HOST)), $f->name);
            self::assertStringNotContainsString('http', (string) $f->meta['label'], $f->name . ' label must be generic');
        }
    }

    public function testCase82EveryAbsoluteUrlUsesAReservedHost(): void
    {
        foreach (self::suite()->fixtures as $f) {
            self::assertSame([], FixtureMeta::foreignHosts($f->html), $f->name);
            self::assertSame([], FixtureMeta::foreignHosts((string) json_encode($f->expected, JSON_UNESCAPED_SLASHES)), $f->name . ' expected.json');
        }
        self::assertSame(['www.real-outlet.in'], FixtureMeta::foreignHosts('<a href="https://www.real-outlet.in/x">x</a><a href="https://schema.org">s</a>'));
    }

    public function testCase83NoImageFilesInFixtures(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $ext = strtolower($file->getExtension());
            self::assertNotContains($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'heic'], (string) $file);
            $head = (string) file_get_contents((string) $file, false, null, 0, 12);
            foreach (["\xFF\xD8\xFF", "\x89PNG", 'GIF8', 'RIFF'] as $magic) {
                self::assertFalse(str_starts_with($head, $magic), (string) $file . ' looks like an image');
            }
            self::assertContains($file->getFilename(), ['page.html', 'expected.json', 'meta.json', 'baseline.json'], 'unexpected file ' . $file);
        }
    }

    public function testCase84CoverageMapIsCompleteAndWritten(): void
    {
        $byScript = [];
        $byLayout = [];
        $covers = [];
        foreach (self::suite()->fixtures as $f) {
            $byScript[(string) $f->meta['script']][] = $f->name;
            $byLayout[(string) $f->meta['layout']][] = $f->name;
            foreach ((array) ($f->meta['covers'] ?? []) as $tag) {
                $covers[(string) $tag][] = $f->name;
            }
        }
        ksort($byScript);
        ksort($byLayout);
        ksort($covers);
        $missing = array_values(array_diff(self::REQUIRED, array_keys($covers)));
        $map = [
            'note' => 'Written by tests/Fixtures/FixtureSuiteTest.php. Do not edit by hand; run the tests and commit the result.',
            'fixtureCount' => self::suite()->count(),
            'required' => self::REQUIRED,
            'missing' => $missing,
            'byScript' => $byScript,
            'byLayout' => $byLayout,
            'covers' => $covers,
            'unitTestedOnly' => ['type:wrapper' => 'tests/Unit/InputTest.php (case 5): a wrapper is known from its URL, and fixture URLs use reserved hosts only'],
        ];
        file_put_contents(self::COVERAGE, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        self::assertSame([], $missing, 'coverage gaps: ' . implode(', ', $missing));
        self::assertGreaterThanOrEqual(40, self::suite()->count());
    }

    public function testCommittedBaselineMatchesTheCurrentRun(): void
    {
        $report = (new Scorer(new Extractor()))->score(self::suite());
        $comparison = $report->compare(Baseline::fromFile(self::DIR . '/baseline.json'));
        self::assertFalse($comparison->isRegression(), $comparison->toText());
    }
}
