<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Source;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\TestCase;

/** PRD cases 21 to 30. */
final class BodyTest extends TestCase
{
    private function trace(): Extractor
    {
        return new Extractor((new ExtractorOptions())->withTrace());
    }

    public function testCase21AllThreeCandidatesAreMeasuredInTheTrace(): void
    {
        $prose = Page::prose(900);
        $html = Page::html(
            '<main><article class="story"><div itemprop="articleBody">' . $prose . '</div></article></main>',
            ['@type' => 'NewsArticle', 'headline' => 'Story', 'articleBody' => Page::proseText(900)],
        );
        $a = $this->trace()->extract($html, Page::URL);
        $sources = array_map(static fn (array $c): string => $c['source'], $a->trace?->candidates('body') ?? []);
        self::assertContains('visible', $sources);
        self::assertContains('microdata', $sources);
        self::assertContains('json_ld', $sources);
        foreach ($a->trace?->candidates('body') ?? [] as $c) {
            if ($c['score'] !== null) {
                self::assertGreaterThan(400, $c['score'], $c['source'] . ' length recorded');
            }
        }
    }

    public function testCase22RelatedBlockLosesToArticleAndDandaProseScores(): void
    {
        $hindi = str_repeat('<p>शहर में आज सुबह से हल्की बारिश हो रही है। नगर निगम ने कहा कि जलभराव वाले इलाकों में पंप लगाए गए हैं। स्थानीय व्यापारियों ने बताया कि बाज़ार में भीड़ बढ़ी है।</p>', 4);
        $related = '<div class="related-stories">' . str_repeat('<p><a href="/a">Related story headline about the city and its wards, read it now</a></p>', 8) . '</div>';
        $html = Page::html('<main>' . $related . '<div class="body-text">' . $hindi . '</div></main>', null, '', 'hi');
        $a = $this->trace()->extract($html, Page::URL);
        self::assertStringContainsString('नगर निगम', $a->bodyText);
        self::assertStringNotContainsString('Related story headline', $a->bodyText);
        self::assertSame(Source::Visible, $a->fieldSources['body']);
    }

    public function testCase23ShortVisibleLosesToLongJsonLd(): void
    {
        $long = str_repeat('The full report says the session ended early because of the holiday, and members agreed. ', 23);
        $html = Page::article('<p>The assembly session ended a day early. The full report follows below.</p><p>More soon on this.</p>', ['articleBody' => $long]);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertGreaterThan(2000, mb_strlen($long));
        self::assertSame(trim($long), $a->bodyText);
        self::assertSame(Source::JsonLd, $a->fieldSources['body']);
        self::assertTrue($a->hasWarning(Warning::VisibleBodyShort));
    }

    public function testCase24BiographyFirstLosesToJsonLdBody(): void
    {
        $bio = '<div class="author-bio"><p>Asha Rao is a senior correspondent who writes on cities and transport.</p></div>';
        $html = Page::article($bio . '<p>Story continues.</p>', ['articleBody' => Page::proseText(900)]);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertStringNotContainsString('senior correspondent', $a->bodyText);
        self::assertStringContainsString('drainage plan', $a->bodyText);
    }

    public function testCase24BiographyOnlyGivesEmptyBody(): void
    {
        $html = Page::html('<main><div class="author-profile"><p>Asha Rao is a senior correspondent who writes on cities and transport.</p></div></main>', ['@type' => 'NewsArticle', 'headline' => 'Opinion']);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('', $a->bodyText);
        self::assertTrue($a->hasWarning(Warning::BiographyOnly));
    }

    public function testCase24BiographyPatternWithoutContainer(): void
    {
        $html = Page::html('<main><div class="x"><p>Asha Rao covers cities, transport and local government for this site.</p></div></main>', ['@type' => 'NewsArticle', 'headline' => 'Opinion']);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('', $a->bodyText);
        self::assertTrue($a->hasWarning(Warning::BiographyOnly));
    }

    public function testCase25UnrelatedBodiesVisibleWinsWithLowerConfidence(): void
    {
        $other = str_repeat('Cricket: the home side won the toss and chose to bat on a dry pitch with five wickets in hand. ', 6);
        $agree = (new Extractor())->extract(Page::article(Page::prose(900), ['articleBody' => Page::proseText(900)]), Page::URL);
        $a = (new Extractor())->extract(Page::article(Page::prose(900), ['articleBody' => $other]), Page::URL);
        self::assertTrue($a->hasWarning(Warning::BodyDisagreement));
        self::assertSame(Source::Visible, $a->fieldSources['body']);
        self::assertStringNotContainsString('Cricket', $a->bodyText);
        self::assertStringContainsString('drainage plan', $a->bodyText);
        self::assertLessThan($agree->confidence, $a->confidence);
        self::assertFalse($agree->hasWarning(Warning::BodyDisagreement));
    }

    public function testCase26AlsoReadNewsletterAndRepeatedHeadlineRemoved(): void
    {
        $body = '<h1>Council asks for a timeline</h1><p class="byline">By Asha Rao</p>' . Page::prose(500)
            . '<p><strong>Also read:</strong> <a href="/x">Port strike called off</a></p>'
            . '<p>ये भी पढ़ें: <a href="/y">दूसरी ख़बर</a></p>'
            . '<div class="newsletter-box"><p>Subscribe to our newsletter for the morning brief.</p></div>'
            . '<div class="share-bar"><a href="/s">Share on social</a></div>'
            . '<blockquote class="twitter-tweet"><p>Post text</p><a href="https://social.example/u/status/1">Nov 10</a></blockquote>'
            . Page::prose(500, 2);
        $a = (new Extractor())->extract(Page::article($body), Page::URL);
        foreach (['Also read', 'Port strike', 'ये भी पढ़ें', 'Subscribe to our newsletter', 'Share on social', 'By Asha Rao', 'Council asks for a timeline'] as $gone) {
            self::assertStringNotContainsString($gone, $a->bodyText, $gone);
        }
        self::assertStringContainsString('<a href="https://social.example/u/status/1">', $a->bodyHtml);
        self::assertStringNotContainsString('Post text', $a->bodyText);
    }

    public function testCase27DangerousMarkupNeverReachesBodyHtml(): void
    {
        $body = Page::prose(500)
            . '<p onclick="steal()">Click <a href="javascript:alert(1)">here</a> and <a href="vbscript:x">there</a> and <a href="data:text/html,x">data</a> for the long text here.</p>'
            . '<iframe src="https://evil.example/"></iframe>'
            . '<p><img src="data:image/png;base64,AAAA" alt="x"><img src="https://img.daily.example/photo/wide-1.jpg" onerror="x()" style="color:red" class="big" alt="Wide photo" width="800" height="600"></p>'
            . '<table><tr><td colspan="2" style="x">cell text that is long enough</td></tr></table><span style="color:red">Styled</span>';
        $a = (new Extractor())->extract(Page::article($body), Page::URL);
        $h = strtolower($a->bodyHtml);
        foreach (['javascript:', 'vbscript:', 'data:', 'onclick', 'onerror', '<iframe', 'style=', 'class=', '<span'] as $bad) {
            self::assertStringNotContainsString($bad, $h, $bad);
        }
        self::assertStringContainsString('<img src="https://img.daily.example/photo/wide-1.jpg" alt="Wide photo" width="800" height="600">', $a->bodyHtml);
        self::assertStringContainsString('<td colspan="2">', $a->bodyHtml);
    }

    public function testCase28BaseHrefIsUsedForRelativeLinks(): void
    {
        $html = Page::article(Page::prose() . '<p>See <a href="plan/full">the full plan</a> for every ward and the whole timetable.</p>', [], '<base href="https://archive.daily.example/2026/">');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertStringContainsString('href="https://archive.daily.example/2026/plan/full"', $a->bodyHtml);
    }

    public function testCase29BodyTextHasBlankLinesAndNoShareText(): void
    {
        $body = '<p>First paragraph of the story with enough words to count as prose here.</p>'
            . '<div class="social-share"><a href="/s">Share</a><a href="/t">Tweet</a></div>'
            . '<p>Second paragraph of the story with enough words to count as prose here.</p>'
            . '<ul><li>One item in the list</li><li>Another item in the list</li></ul>' . Page::prose(400);
        $a = (new Extractor())->extract(Page::article($body), Page::URL);
        self::assertStringStartsWith("First paragraph of the story with enough words to count as prose here.\n\nSecond paragraph", $a->bodyText);
        self::assertStringContainsString("One item in the list\nAnother item in the list", $a->bodyText);
        self::assertStringNotContainsString('Tweet', $a->bodyText);
    }

    public function testCase30ShortTamilArticleByWordsPassesByCharacters(): void
    {
        $ta = 'நகரில் இன்று காலை முதல் லேசான மழை பெய்து வருகிறது, அடுத்த இரண்டு நாட்களுக்கு மேகமூட்டம் தொடரும் என்று வானிலை ஆய்வு மையம் தெரிவித்துள்ளது.';
        $html = Page::article('<p>' . $ta . '</p><p>' . $ta . '</p><p>' . $ta . '</p>', [], '', 'ta');
        $a = (new Extractor())->extract($html, 'https://www.ta-daily.example/city/rain');
        self::assertGreaterThanOrEqual(400, $a->charCount);
        self::assertLessThan(80, $a->wordCount);
        self::assertSame('whitespace', $a->wordCountMethod);
        self::assertTrue($a->isReadable());
    }

    public function testCase30ScriptsWithoutSpacesUseTheBreakerOrCharacters(): void
    {
        $th = str_repeat('ฝนตกในเมืองตั้งแต่เช้า ', 30);
        $html = Page::article('<p>' . $th . '</p>');
        $a = (new Extractor())->extract($html, Page::URL);
        $expected = class_exists(\IntlBreakIterator::class) ? 'intl' : 'characters';
        self::assertSame($expected, $a->wordCountMethod);
        $b = (new Extractor((new ExtractorOptions())->withIntl(false)))->extract($html, Page::URL);
        self::assertSame('characters', $b->wordCountMethod);
        if ($expected === 'intl') {
            self::assertGreaterThan($a->wordCount, $b->wordCount, 'the fallback counts letters, so more words');
        } else {
            self::assertSame($a->wordCount, $b->wordCount);
        }
    }
}
