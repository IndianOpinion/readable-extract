<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Exception\InvalidUrlException;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\PageContext;
use IndianOpinion\ReadableExtract\PageType;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRD cases 1 to 9. */
final class InputTest extends TestCase
{
    public function testCase1RelativeLinksResolveAgainstTheGivenUrl(): void
    {
        $html = Page::article(Page::prose() . '<p>See <a href="../budget/plan">the plan</a> for details of every ward and the full timetable.</p>');
        $a = (new Extractor())->extract($html, 'https://www.daily.example/city/news/story-1');
        self::assertStringContainsString('href="https://www.daily.example/city/budget/plan"', $a->bodyHtml);
    }

    #[DataProvider('emptyInputs')]
    public function testCase2EmptyInputGivesEmptyPageType(string $html): void
    {
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(PageType::Empty, $a->pageType);
        self::assertTrue($a->hasWarning(Warning::EmptyInput));
        self::assertSame('', $a->bodyText);
        self::assertSame(0.0, $a->confidence);
    }

    /** @return array<string, array{string}> */
    public static function emptyInputs(): array
    {
        return ['empty' => [''], 'whitespace' => ["  \n"]];
    }

    public function testCase3InputOverTheCapIsCutAndStillParses(): void
    {
        $prose = Page::prose(2000);
        $filler = str_repeat('<div class="filler">' . str_repeat('x', 1000) . '</div>', 6 * 1024);
        $html = Page::article($prose) . $filler;
        self::assertGreaterThan(6 * 1024 * 1024, strlen($html));
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertTrue($a->hasWarning(Warning::InputTruncated));
        self::assertSame(PageType::Article, $a->pageType);
        self::assertStringContainsString('drainage plan', $a->bodyText);
    }

    public function testCase3CutHappensOnACharacterBoundary(): void
    {
        $html = '<p>' . str_repeat('अ', 600) . '</p>';
        $a = (new Extractor((new ExtractorOptions())->withInputCap(1001)))->extract($html, Page::URL);
        self::assertTrue($a->hasWarning(Warning::InputTruncated));
        self::assertTrue(mb_check_encoding($a->bodyText, 'UTF-8'));
        self::assertFalse($a->hasWarning(Warning::InvalidEncoding));
    }

    #[DataProvider('badUrls')]
    public function testCase4InvalidUrlsThrow(string $url): void
    {
        $this->expectException(InvalidUrlException::class);
        (new Extractor())->extract('<p>x</p>', $url);
    }

    /** @return array<string, array{string}> */
    public static function badUrls(): array
    {
        return [
            'ftp' => ['ftp://x'],
            'relative' => ['/a'],
            'too long' => ['https://www.daily.example/' . str_repeat('a', 9 * 1024)],
            'userinfo' => ['https://u:p@x.example/a'],
        ];
    }

    public function testCase5WrapperUrlIsNotExtracted(): void
    {
        $a = (new Extractor())->extract(Page::article(Page::prose()), 'https://news.google.com/rss/articles/CBMiQWh0dHBzOi8vZXhhbXBsZQ?oc=5');
        self::assertSame(PageType::Wrapper, $a->pageType);
        self::assertTrue($a->hasWarning(Warning::WrapperUrlNotResolved));
        self::assertSame('', $a->bodyText);
        self::assertNull($a->title);
    }

    public function testCase5WrapperHostsAreConfigurable(): void
    {
        $o = (new ExtractorOptions())->withWrapperHosts(['links.aggregator.example' => ['/r/']]);
        $a = (new Extractor($o))->extract(Page::article(Page::prose()), 'https://links.aggregator.example/r/abc');
        self::assertSame(PageType::Wrapper, $a->pageType);
    }

    public function testCase6Windows1252MetaDecodes(): void
    {
        $html = "<html><head><meta charset=\"windows-1252\"></head><body><p>Caf\xE9 owners call the rule \x93quoted\x94 and fair.</p></body></html>";
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame("Caf\u{00E9} owners call the rule \u{201C}quoted\u{201D} and fair.", $a->bodyText);
    }

    public function testCase6HeaderCharsetOverridesMeta(): void
    {
        $html = "<html><head><meta charset=\"utf-8\"></head><body><p>Caf\xE9 au lait for the ward office staff.</p></body></html>";
        $a = (new Extractor())->extract($html, Page::URL, new PageContext(contentType: 'text/html; charset=windows-1252'));
        self::assertStringContainsString("Caf\u{00E9}", $a->bodyText);
        $b = (new Extractor())->extract($html, Page::URL, new PageContext(headers: ['Content-Type' => 'text/html; charset=ISO-8859-1']));
        self::assertStringContainsString("Caf\u{00E9}", $b->bodyText);
    }

    public function testCase6InvalidSequencesAreReplacedAndCounted(): void
    {
        $html = "<p>Good text \xC3\x28 and more \xFF text here.</p>";
        $a = (new Extractor((new ExtractorOptions())->withTrace()))->extract($html, Page::URL);
        self::assertTrue($a->hasWarning(Warning::InvalidEncoding));
        self::assertStringContainsString("\u{FFFD}", $a->bodyText);
        self::assertSame(2, $a->trace?->notes()['invalid_sequences'] ?? null);
    }

    public function testCase7Utf8DeclaredAsLatinIsReadAsUtf8(): void
    {
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"></head><body><p>नगर निगम का नया दफ़्तर खुला।</p></body></html>';
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('नगर निगम का नया दफ़्तर खुला।', $a->bodyText);
        self::assertTrue($a->hasWarning(Warning::CharsetMismatch));
    }

    public function testCase8DecomposedDevanagariComesOutNfc(): void
    {
        if (!class_exists(\Normalizer::class)) {
            self::markTestSkipped('ext-intl not loaded');
        }
        // NA + NUKTA (U+0928 U+093C) composes to NNNA (U+0929) under NFC.
        $html = "<p>\u{0928}\u{093C}\u{092F}\u{093E} \u{0905}\u{0927}\u{094D}\u{092F}\u{093E}\u{092F} and a sentence long enough to score.</p>";
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(\Normalizer::normalize($a->bodyText, \Normalizer::FORM_C), $a->bodyText);
        self::assertStringStartsWith("\u{0929}\u{092F}\u{093E}", $a->bodyText);
        self::assertFalse($a->hasWarning(Warning::NoNormalizer));
    }

    public function testCase8WithoutIntlTextPassesThroughWithWarning(): void
    {
        $html = "<p>\u{0928}\u{093C}\u{092F}\u{093E} text and a sentence long enough to score.</p>";
        $a = (new Extractor((new ExtractorOptions())->withIntl(false)))->extract($html, Page::URL);
        self::assertStringStartsWith("\u{0928}\u{093C}", $a->bodyText);
        self::assertTrue($a->hasWarning(Warning::NoNormalizer));
        self::assertCount(1, array_filter($a->warnings, static fn (Warning $w): bool => $w === Warning::NoNormalizer));
    }

    public function testCase9EntitiesDecodeZwjKeptZwspRemoved(): void
    {
        $html = "<html><head><title>A&nbsp;&amp;&nbsp;B</title></head><body><p>&#2309;&nbsp;x \u{0D28}\u{0D4D}\u{200D} y\u{200B}z, a sentence long enough to score.</p></body></html>";
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame("\u{0905} x \u{0D28}\u{0D4D}\u{200D} yz, a sentence long enough to score.", $a->bodyText);
        self::assertSame('A & B', $a->title);
    }
}
