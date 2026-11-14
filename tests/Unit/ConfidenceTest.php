<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Fetch\RobotsDirectives;
use IndianOpinion\ReadableExtract\PageContext;
use IndianOpinion\ReadableExtract\ScoringWeights;
use IndianOpinion\ReadableExtract\Source;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\TestCase;

/** PRD cases 62 to 64, the confidence formula, and robots meta handling in extraction. */
final class ConfidenceTest extends TestCase
{
    private const W = ScoringWeights::DEFAULTS;

    public function testCase62FormulaMatchesTheDocumentedOne(): void
    {
        $clean = (new Extractor())->extract(Page::article(Page::prose()), Page::URL);
        self::assertSame(self::W['confVisible'], $clean->confidence);

        $agree = (new Extractor())->extract(Page::article(Page::prose(900), ['articleBody' => Page::proseText(900)]), Page::URL);
        self::assertSame(self::W['confVisibleAgreed'], $agree->confidence);

        $other = str_repeat('Cricket: the home side won the toss and chose to bat on a dry pitch with five wickets in hand. ', 6);
        $dis = (new Extractor())->extract(Page::article(Page::prose(900), ['articleBody' => $other]), Page::URL);
        self::assertTrue($dis->hasWarning(Warning::BodyDisagreement));
        self::assertEqualsWithDelta(self::W['confVisible'] * self::W['factorBodyDisagreement'], $dis->confidence, 0.0001);

        $short = (new Extractor())->extract(Page::article('<p>Short visible teaser of the story, a single line only.</p>', ['articleBody' => Page::proseText(900)]), Page::URL);
        self::assertTrue($short->hasWarning(Warning::VisibleBodyShort));
        self::assertEqualsWithDelta(self::W['confStructured'] * self::W['factorVisibleBodyShort'], $short->confidence, 0.0001);

        $bio = (new Extractor())->extract(Page::html('<div class="author-bio"><p>Asha Rao is a senior correspondent who writes on cities.</p></div>', ['@type' => 'NewsArticle', 'headline' => 'H']), Page::URL);
        self::assertSame(0.0, $bio->confidence);

        $video = (new Extractor())->extract(Page::html(Page::prose(), ['@type' => 'VideoObject', 'name' => 'V']), Page::URL);
        self::assertSame(0.0, $video->confidence);

        $untyped = (new Extractor())->extract('<html><body>' . Page::prose() . '</body></html>', Page::URL);
        self::assertEqualsWithDelta(self::W['confVisible'] * self::W['factorNoTypeSignal'], $untyped->confidence, 0.0001);
    }

    public function testCase62ConfidenceIsClampedAndWeightsOverridable(): void
    {
        $o = (new ExtractorOptions())->withScoringWeights(['confVisible' => 3.0]);
        $a = (new Extractor($o))->extract(Page::article(Page::prose()), Page::URL);
        self::assertSame(1.0, $a->confidence);
        self::assertSame(1.0, $a->fieldConfidence['body']);
    }

    public function testCase62FieldSourcesAndConfidences(): void
    {
        $a = (new Extractor())->extract(Page::article(Page::prose(), ['datePublished' => '2026-11-10T09:15:00+05:30', 'author' => ['@type' => 'Person', 'name' => 'Asha Rao']]), Page::URL);
        self::assertSame(['body', 'title', 'authors', 'publishedAt', 'leadImage'], array_keys($a->fieldSources));
        self::assertSame(Source::Visible, $a->fieldSources['body']);
        self::assertSame(Source::JsonLd, $a->fieldSources['authors']);
        foreach ($a->fieldConfidence as $v) {
            self::assertGreaterThanOrEqual(0.0, $v);
            self::assertLessThanOrEqual(1.0, $v);
        }
    }

    public function testCase63IsReadable(): void
    {
        self::assertTrue((new Extractor())->extract(Page::article(Page::prose()), Page::URL)->isReadable());
        $links = '';
        for ($i = 0; $i < 40; $i++) {
            $links .= '<li><a href="/s/' . $i . '">Headline number ' . $i . ' for the section</a></li>';
        }
        self::assertFalse((new Extractor())->extract(Page::html('<ul>' . $links . '</ul>'), 'https://www.daily.example/india/')->isReadable());
        self::assertFalse((new Extractor())->extract(Page::article('<p>Only a short body here, well under the prose minimum.</p>'), Page::URL)->isReadable());
        $low = (new Extractor((new ExtractorOptions())->withReadableBar(0.95)))->extract(Page::article(Page::prose()), Page::URL);
        self::assertFalse($low->isReadable(), 'confidence 0.9 is under a 0.95 bar');
    }

    public function testCase64TraceListsLosersAndIsOffByDefault(): void
    {
        $html = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'Headline'], '<title>Title tag here</title><meta property="og:title" content="OG title">');
        self::assertNull((new Extractor())->extract($html, Page::URL)->trace);
        $a = (new Extractor((new ExtractorOptions())->withTrace()))->extract($html, Page::URL);
        $titles = $a->trace?->candidates('title') ?? [];
        self::assertGreaterThanOrEqual(3, count($titles));
        self::assertTrue($titles[0]['accepted']);
        self::assertFalse($titles[1]['accepted']);
        self::assertStringContainsString('lower in order', $titles[1]['reason']);
        self::assertStringContainsString('[title]', $a->trace?->toText() ?? '');
        self::assertArrayHasKey('fields', $a->toArray()['trace']);
    }

    public function testRobotsDirectivesInContextRefuseBodyOrImage(): void
    {
        $html = Page::article(Page::prose(), ['image' => 'https://img.daily.example/lead.jpg']);
        $noarchive = new PageContext(robots: new RobotsDirectives(['noarchive']));
        $a = (new Extractor())->extract($html, Page::URL, $noarchive);
        self::assertSame('', $a->bodyText);
        self::assertTrue($a->hasWarning(Warning::RobotsMeta));
        $noimage = new PageContext(robots: new RobotsDirectives(['noimageai']));
        $b = (new Extractor())->extract($html, Page::URL, $noimage);
        self::assertNull($b->leadImage);
        self::assertNotSame('', $b->bodyText);
        $noindex = new PageContext(robots: new RobotsDirectives(['noindex']));
        $c = (new Extractor())->extract($html, Page::URL, $noindex);
        self::assertNotSame('', $c->bodyText);
        self::assertNotNull($c->leadImage);
    }
}
