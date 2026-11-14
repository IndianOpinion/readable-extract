<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Exception\ConfigurationException;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Fetch\FetchOptions;
use IndianOpinion\ReadableExtract\Internal\Url;
use IndianOpinion\ReadableExtract\ScoringWeights;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** URL resolution and option validation. */
final class HelpersTest extends TestCase
{
    #[DataProvider('resolutions')]
    public function testRfc3986Resolution(string $ref, ?string $expected): void
    {
        self::assertSame($expected, Url::resolve('https://www.daily.example/a/b/c?q=1', $ref));
    }

    /** @return array<string, array{string, ?string}> */
    public static function resolutions(): array
    {
        return [
            'relative' => ['d', 'https://www.daily.example/a/b/d'],
            'dot dot' => ['../d', 'https://www.daily.example/a/d'],
            'absolute path' => ['/x/./y/../z', 'https://www.daily.example/x/z'],
            'scheme relative' => ['//cdn.daily.example/i.jpg', 'https://cdn.daily.example/i.jpg'],
            'query only' => ['?p=2', 'https://www.daily.example/a/b/c?p=2'],
            'fragment' => ['#top', 'https://www.daily.example/a/b/c?q=1#top'],
            'absolute' => ['HTTP://Other.Example/P', 'http://other.example/P'],
            'empty' => ['  ', null],
            'javascript' => ['javascript:alert(1)', 'javascript:alert(1)'],
        ];
    }

    public function testOptionsAreImmutableAndValidated(): void
    {
        $a = new ExtractorOptions();
        $b = $a->withProseMinimum(250);
        self::assertSame(400, $a->proseMinimum());
        self::assertSame(250, $b->proseMinimum());
        $cases = [
            static fn () => $a->withProseMinimum(0),
            static fn () => $a->withReadableBar(1.5),
            static fn () => $a->withDefaultTimezone('Mars/Base'),
            static fn () => $a->withScoringWeights(['noSuchWeight' => 1]),
            static fn () => $a->withNonArticlePathHints(['x' => 'article']),
            static fn () => $a->withPositiveHints(['']),
            static fn () => (new FetchOptions('Reader/1 (+https://reader.example)'))->withMaxRedirects(11),
            static fn () => (new FetchOptions('Reader/1 (+https://reader.example)'))->withAllowedPorts([0]),
            static fn () => (new FetchOptions('Reader/1 (+https://reader.example)'))->withAuthorization("a\nb"),
        ];
        foreach ($cases as $i => $bad) {
            try {
                $bad();
                self::fail('option ' . $i . ' should be refused');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testScoringWeightsDefaultsAndOverrides(): void
    {
        $w = new ScoringWeights(['hintWeight' => 10]);
        self::assertSame(10.0, $w->get('hintWeight'));
        self::assertSame(ScoringWeights::DEFAULTS['commaWeight'], $w->get('commaWeight'));
        self::assertCount(count(ScoringWeights::DEFAULTS), $w->all());
    }
}
