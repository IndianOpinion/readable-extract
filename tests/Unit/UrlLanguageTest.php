<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Internal\Url;
use IndianOpinion\ReadableExtract\PageContext;
use IndianOpinion\ReadableExtract\PageType;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\TestCase;

/** PRD cases 53 to 61. */
final class UrlLanguageTest extends TestCase
{
    public function testCase53CanonicalToHomePageIgnored(): void
    {
        $html = Page::html(Page::prose(), null, '<link rel="canonical" href="https://www.daily.example/">');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(Page::URL, $a->canonicalUrl);
        self::assertTrue($a->hasWarning(Warning::CanonicalIgnored));
    }

    public function testCase53CanonicalToAnotherOutletOrSchemeIgnored(): void
    {
        $other = Page::html(Page::prose(), null, '<link rel="canonical" href="https://www.other-outlet.example/story">');
        $a = (new Extractor())->extract($other, Page::URL);
        self::assertSame(Page::URL, $a->canonicalUrl);
        self::assertTrue($a->hasWarning(Warning::CanonicalIgnored));
        $good = Page::html(Page::prose(), null, '<link rel="canonical" href="https://m.daily.example/city/story-1001-full">');
        self::assertSame('https://m.daily.example/city/story-1001-full', (new Extractor())->extract($good, Page::URL)->canonicalUrl);
    }

    public function testCase54AmpReportsCanonicalAndAmpUrl(): void
    {
        $html = Page::html(Page::prose(), null, '<link rel="canonical" href="https://www.daily.example/city/story-1001">', 'en', ' amp');
        $a = (new Extractor())->extract($html, 'https://www.daily.example/amp/city/story-1001');
        self::assertSame('https://www.daily.example/city/story-1001', $a->canonicalUrl);
        self::assertSame('https://www.daily.example/amp/city/story-1001', $a->ampUrl);
        $bolt = Page::html(Page::prose(), null, '<link rel="canonical" href="https://www.daily.example/city/story-1001">', 'en', " \u{26A1}");
        self::assertSame('https://www.daily.example/amp/city/story-1001', (new Extractor())->extract($bolt, 'https://www.daily.example/amp/city/story-1001')->ampUrl);
    }

    public function testCase55TrackingAndFragmentStripped(): void
    {
        $html = Page::html(Page::prose(), null, '<link rel="canonical" href="https://www.daily.example/city/story?id=7&utm_source=x&fbclid=y&UTM_Medium=z#top">');
        self::assertSame('https://www.daily.example/city/story?id=7', (new Extractor())->extract($html, Page::URL)->canonicalUrl);
        self::assertSame('https://a.example/p?ref=1', Url::stripTracking('https://a.example/p?ref=1&gclid=2', ['gclid']));
    }

    public function testCase56OwnHostNeverReported(): void
    {
        $o = (new ExtractorOptions())->withOwnHosts(['www.my-site.example']);
        $html = Page::html(Page::prose(), null, '<link rel="canonical" href="https://my-site.example/copied-story">');
        $a = (new Extractor($o))->extract($html, 'https://www.my-site.example/copied-story');
        self::assertNull($a->canonicalUrl);
        self::assertNull($a->host);
        self::assertTrue($a->hasWarning(Warning::OwnHost));
    }

    public function testCase57MirrorLabelsStrippedAndNormaliserReplaces(): void
    {
        $a = (new Extractor())->extract(Page::html(Page::prose()), 'https://m.example.com/news/1');
        self::assertSame('example.com', $a->host);
        self::assertSame('example.com', Url::normaliseHost('WWW.AMP.Example.com.'));
        $o = (new ExtractorOptions())->withHostNormaliser(static fn (string $h): string => 'custom:' . $h);
        self::assertSame('custom:m.example.com', (new Extractor($o))->extract(Page::html(Page::prose()), 'https://m.example.com/news/1')->host);
    }

    public function testCase58LanguageOrderAndMismatch(): void
    {
        $hindi = str_repeat('<p>शहर में आज सुबह से हल्की बारिश हो रही है, और मौसम विभाग ने अनुमान लगाया है।</p>', 6);
        $a = (new Extractor())->extract(Page::html($hindi, null, '', 'en'), Page::URL);
        self::assertSame('en', $a->language);
        self::assertSame('Deva', $a->detectedScript);
        self::assertTrue($a->hasWarning(Warning::LanguageMismatch));

        $ld = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'H', 'inLanguage' => 'hi_in'], '', 'en');
        self::assertSame('hi-IN', (new Extractor())->extract($ld, Page::URL)->language);
        $og = '<html><head><meta property="og:locale" content="ta_IN"></head><body>' . Page::prose() . '</body></html>';
        self::assertSame('ta-IN', (new Extractor())->extract($og, Page::URL)->language);
        $hdr = '<html><body>' . Page::prose() . '</body></html>';
        self::assertSame('bn', (new Extractor())->extract($hdr, Page::URL, new PageContext(headers: ['Content-Language' => 'bn']))->language);
        self::assertFalse((new Extractor())->extract(Page::html(Page::prose()), Page::URL)->hasWarning(Warning::LanguageMismatch));
    }

    public function testCase59SiteNameOrder(): void
    {
        $og = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'H', 'publisher' => ['@type' => 'Organization', 'name' => 'Publisher Name']], '<meta property="og:site_name" content="Daily Site">');
        self::assertSame('Daily Site', (new Extractor())->extract($og, Page::URL)->siteName);
        $pub = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'H', 'publisher' => ['@type' => 'Organization', 'name' => 'Publisher Name']]);
        self::assertSame('Publisher Name', (new Extractor())->extract($pub, Page::URL)->siteName);
        self::assertSame('daily.example', (new Extractor())->extract(Page::html(Page::prose()), Page::URL)->siteName);
    }

    public function testCase60PaywalledShortBodyAndHiddenTextNeverReturned(): void
    {
        $html = Page::html(
            '<article><p>Bond yields rose for a third day as traders waited for the minutes.</p><div class="paywall"><p>SECRETTEXT for subscribers only, a long paragraph that is shipped in the page markup.</p></div></article>',
            ['@type' => 'NewsArticle', 'headline' => 'Bonds', 'isAccessibleForFree' => 'False', 'hasPart' => ['@type' => 'WebPageElement', 'isAccessibleForFree' => false, 'cssSelector' => '.paywall']],
        );
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(PageType::Paywalled, $a->pageType);
        self::assertFalse($a->accessibleForFree);
        self::assertTrue($a->hasWarning(Warning::Paywalled));
        self::assertStringNotContainsString('SECRETTEXT', $a->bodyText);
        self::assertStringNotContainsString('SECRETTEXT', $a->bodyHtml);
    }

    public function testCase60FreeLongArticleStaysArticle(): void
    {
        $a = (new Extractor())->extract(Page::article(Page::prose(), ['isAccessibleForFree' => false]), Page::URL);
        self::assertSame(PageType::Article, $a->pageType);
        self::assertFalse($a->accessibleForFree);
    }

    public function testCase61SponsoredSignalsReportedTypeUnchanged(): void
    {
        $html = Page::article('<span class="label">Partner content</span>' . Page::prose());
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertContains('label:Partner content', $a->sponsoredSignals);
        self::assertSame(PageType::Article, $a->pageType);
        $ld = Page::html(Page::prose(), ['@type' => 'AdvertiserContentArticle', 'headline' => 'H']);
        self::assertContains('json_ld:AdvertiserContentArticle', (new Extractor())->extract($ld, Page::URL)->sponsoredSignals);
    }
}
