<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Internal\Dates;
use IndianOpinion\ReadableExtract\PageContext;
use IndianOpinion\ReadableExtract\Source;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRD cases 47 to 52. */
final class DateTest extends TestCase
{
    private static function utc(?\DateTimeImmutable $d): ?string
    {
        return $d?->format('Y-m-d\TH:i:s\Z');
    }

    public function testCase47JsonLdBeatsTimeElementModifiedReadSeparately(): void
    {
        $html = Page::article(
            '<header><time datetime="2026-11-01T10:00:00+05:30">1 Nov</time></header>' . Page::prose(),
            ['datePublished' => '2026-11-10T09:15:00+05:30', 'dateModified' => '2026-11-10T12:00:00+05:30'],
        );
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('2026-11-10T03:45:00Z', self::utc($a->publishedAt));
        self::assertSame('2026-11-10T06:30:00Z', self::utc($a->modifiedAt));
        self::assertSame(Source::JsonLd, $a->fieldSources['publishedAt']);
    }

    public function testCase47FallsBackToMetaThenTimeElement(): void
    {
        $meta = Page::html(Page::prose(), null, '<meta property="article:published_time" content="2026-11-11T07:00:00+05:30">');
        self::assertSame('2026-11-11T01:30:00Z', self::utc((new Extractor())->extract($meta, Page::URL)->publishedAt));
        $time = Page::html('<article><header><time datetime="2026-11-09T18:45:00+05:30">9 Nov</time></header>' . Page::prose() . '</article>');
        self::assertSame('2026-11-09T13:15:00Z', self::utc((new Extractor())->extract($time, Page::URL)->publishedAt));
    }

    public function testCase48OffsetlessDateReadInDefaultZone(): void
    {
        $a = (new Extractor())->extract(Page::article(Page::prose(), ['datePublished' => '2026-11-14T10:30:00']), Page::URL);
        self::assertSame('2026-11-14T05:00:00Z', self::utc($a->publishedAt));
        self::assertTrue($a->hasWarning(Warning::AssumedTimezone));
        $b = (new Extractor((new ExtractorOptions())->withDefaultTimezone('UTC')))->extract(Page::article(Page::prose(), ['datePublished' => '2026-11-14T10:30:00']), Page::URL);
        self::assertSame('2026-11-14T10:30:00Z', self::utc($b->publishedAt));
    }

    public function testCase48OriginalStringKeptInTrace(): void
    {
        $a = (new Extractor((new ExtractorOptions())->withTrace()))->extract(Page::article(Page::prose(), ['datePublished' => '2026-11-14T10:30:00+05:30']), Page::URL);
        $c = $a->trace?->candidates('publishedAt') ?? [];
        self::assertSame('2026-11-14T10:30:00+05:30', $c[0]['value'] ?? null);
    }

    #[DataProvider('visibleForms')]
    public function testCase49VisibleForms(string $text, string $expected): void
    {
        $d = new Dates('Asia/Kolkata', null, 86400);
        self::assertSame($expected, self::utc($d->visible($text)['date'] ?? null), $text);
    }

    /** @return array<string, array{string, string}> */
    public static function visibleForms(): array
    {
        return [
            'dmy ist' => ['14 Nov 2026, 10:30 IST', '2026-11-14T05:00:00Z'],
            'mdy am ist' => ['Nov 14, 2026 10:30 am IST', '2026-11-14T05:00:00Z'],
            'slash day first' => ['Updated: 14/11/2026', '2026-11-13T18:30:00Z'],
            'dash with time' => ['Published On: 14-11-2026 10:30', '2026-11-14T05:00:00Z'],
            'pm' => ['November 14, 2026 4:05 pm IST', '2026-11-14T10:35:00Z'],
            'hindi month' => ['14 नवंबर 2026, 10:30 IST', '2026-11-14T05:00:00Z'],
            'hindi digits' => ['१४ नवंबर २०२६', '2026-11-13T18:30:00Z'],
            'marathi month' => ['14 नोव्हेंबर 2026', '2026-11-13T18:30:00Z'],
            'gmt' => ['14 Nov 2026 05:00 GMT', '2026-11-14T05:00:00Z'],
        ];
    }

    public function testCase50RelativeNeedsAReferenceTime(): void
    {
        $html = Page::html('<article><span class="timestamp">2 hours ago</span>' . Page::prose() . '</article>');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertNull($a->publishedAt);
        self::assertTrue($a->hasWarning(Warning::RelativeDate));
        $ref = new \DateTimeImmutable('2026-11-14T12:00:00Z');
        $b = (new Extractor())->extract($html, Page::URL, new PageContext(referenceTime: $ref));
        self::assertSame('2026-11-14T10:00:00Z', self::utc($b->publishedAt));
        $d = new Dates('Asia/Kolkata', $ref, 86400);
        self::assertSame('2026-11-13T12:00:00Z', self::utc($d->visible('Yesterday')['date'] ?? null));
        self::assertSame('2026-11-14T09:00:00Z', self::utc($d->visible('3 घंटे पहले')['date'] ?? null));
    }

    public function testCase51ImplausibleDatesRejected(): void
    {
        $html = Page::article(Page::prose(), ['datePublished' => '2031-01-01T10:00:00+05:30'], '<meta property="article:published_time" content="1970-01-01T00:00:00Z">');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertNull($a->publishedAt);
        self::assertTrue($a->hasWarning(Warning::ImplausibleDate));
    }

    public function testCase51ModifiedBeforePublishedIsDropped(): void
    {
        $a = (new Extractor())->extract(Page::article(Page::prose(), ['datePublished' => '2026-11-10T10:00:00Z', 'dateModified' => '2026-11-09T10:00:00Z']), Page::URL);
        self::assertSame('2026-11-10T10:00:00Z', self::utc($a->publishedAt));
        self::assertNull($a->modifiedAt);
        self::assertTrue($a->hasWarning(Warning::ModifiedBeforePublished));
    }

    public function testCase52UndatedPageGivesNullNeverToday(): void
    {
        $a = (new Extractor())->extract(Page::article(Page::prose()), Page::URL);
        self::assertNull($a->publishedAt);
        self::assertNull($a->modifiedAt);
        self::assertSame(Source::None, $a->fieldSources['publishedAt']);
    }
}
