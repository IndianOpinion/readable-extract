<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\PageType;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\TestCase;

/** PRD cases 14 to 20 (case 14 per fixture is in FixtureSuiteTest). */
final class PageTypeTest extends TestCase
{
    public function testCase14OnePageTypeIsAlwaysAssigned(): void
    {
        $a = (new Extractor())->extract(Page::article(Page::prose()), Page::URL);
        self::assertSame(PageType::Article, $a->pageType);
    }

    public function testCase15LiveBlogPostingGivesLiveblog(): void
    {
        $html = Page::html('<p>Live coverage of the count.</p>', ['@type' => 'LiveBlogPosting', 'headline' => 'Live: counting day']);
        self::assertSame(PageType::Liveblog, (new Extractor())->extract($html, Page::URL)->pageType);
    }

    public function testCase15VideoObjectMainEntityGivesVideo(): void
    {
        $html = Page::html('<p>Watch the bulletin. ' . str_repeat('More text. ', 10) . '</p>', [
            '@type' => 'WebPage', 'mainEntity' => ['@type' => 'VideoObject', 'name' => 'Bulletin'],
        ]);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(PageType::Video, $a->pageType);
    }

    public function testCase15ArticleWithEmbeddedVideoStaysArticle(): void
    {
        $html = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'Story', 'video' => ['@type' => 'VideoObject', 'name' => 'Clip']]);
        self::assertSame(PageType::Article, (new Extractor())->extract($html, Page::URL)->pageType);
    }

    public function testCase15GalleryAndCollectionTypes(): void
    {
        $g = Page::html('<p>Pictures from the festival.</p>', ['@type' => 'ImageGallery', 'name' => 'Pictures']);
        self::assertSame(PageType::Gallery, (new Extractor())->extract($g, Page::URL)->pageType);
        $i = Page::html('<p>Latest stories.</p>', ['@type' => 'CollectionPage', 'name' => 'Latest']);
        self::assertSame(PageType::Index, (new Extractor())->extract($i, Page::URL)->pageType);
    }

    public function testCase15OgTypeIsTheFallback(): void
    {
        $html = Page::html('<p>Clip.</p>', null, '<meta property="og:type" content="video.other">');
        self::assertSame(PageType::Video, (new Extractor())->extract($html, Page::URL)->pageType);
    }

    public function testCase16VideoPathWithoutArticleDataGivesVideo(): void
    {
        $html = Page::html('<main>' . Page::prose() . '</main>', null, '<meta property="og:type" content="article">');
        self::assertSame(PageType::Video, (new Extractor())->extract($html, 'https://www.daily.example/video/clip-1')->pageType);
    }

    public function testCase16VideoPathWithNewsArticleStaysArticle(): void
    {
        $html = Page::article(Page::prose());
        self::assertSame(PageType::Article, (new Extractor())->extract($html, 'https://www.daily.example/video/clip-1')->pageType);
    }

    public function testCase16HintListIsConfigurable(): void
    {
        $o = (new ExtractorOptions())->withNonArticlePathHints(['clips' => 'video']);
        $html = Page::html('<main>' . Page::prose() . '</main>');
        self::assertSame(PageType::Video, (new Extractor($o))->extract($html, 'https://www.daily.example/clips/1')->pageType);
        self::assertSame(PageType::Article, (new Extractor($o))->extract($html, 'https://www.daily.example/video/1')->pageType);
    }

    public function testCase17SectionFrontOfFortyLinksIsIndex(): void
    {
        $links = '';
        for ($i = 1; $i <= 40; $i++) {
            $links .= '<li><a href="/india/story-' . $i . '">Headline number ' . $i . ' about the state</a></li>';
        }
        $a = (new Extractor())->extract(Page::html('<main><h1>India</h1><ul>' . $links . '</ul></main>'), 'https://www.daily.example/india/');
        self::assertSame(PageType::Index, $a->pageType);
        self::assertTrue($a->hasWarning(Warning::NotAnArticle));
    }

    public function testCase18PlaylistReturnsTitleAndImageButNoBody(): void
    {
        $cards = str_repeat('<div class="card"><h3>Evening bulletin clip with the main stories of the day</h3><p>Two minutes of the main stories, with reports from the capital.</p></div>', 6);
        $html = Page::html($cards, ['@type' => 'VideoObject', 'name' => 'Bulletin'], '<meta property="og:title" content="Evening bulletin"><meta property="og:image" content="https://img.daily.example/bulletin-wide.jpg">');
        $a = (new Extractor())->extract($html, 'https://www.daily.example/videos/playlist');
        self::assertSame(PageType::Video, $a->pageType);
        self::assertSame('Evening bulletin', $a->title);
        self::assertSame('https://img.daily.example/bulletin-wide.jpg', $a->leadImage?->url);
        self::assertSame('', $a->bodyText);
        self::assertSame('', $a->bodyHtml);
        self::assertTrue($a->hasWarning(Warning::NotAnArticle));
        self::assertFalse($a->isReadable());
    }

    public function testCase19LiveblogUpdatesInOrderModifiedIsNewest(): void
    {
        $updates = [];
        foreach (['10:20', '09:40', '09:05', '08:30', '08:00'] as $i => $t) {
            $updates[] = ['@type' => 'BlogPosting', 'datePublished' => "2026-11-14T$t:00+05:30", 'articleBody' => 'Update text number ' . (5 - $i) . ' with enough words to read.'];
        }
        $html = Page::html('<main></main>', ['@type' => 'LiveBlogPosting', 'headline' => 'Live', 'liveBlogUpdate' => $updates]);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(PageType::Liveblog, $a->pageType);
        $positions = [];
        for ($n = 5; $n >= 1; $n--) {
            $positions[] = strpos($a->bodyText, 'Update text number ' . $n);
        }
        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'updates keep page order');
        self::assertSame('2026-11-14T04:50:00Z', $a->modifiedAt?->format('Y-m-d\TH:i:s\Z'));
        self::assertSame('2026-11-14T02:30:00Z', $a->publishedAt?->format('Y-m-d\TH:i:s\Z'), 'first update when no start time');
    }

    public function testCase20ScriptOnlyShellIsScriptRendered(): void
    {
        $html = Page::html('<div id="root"></div><script src="https://static.daily.example/app.js"></script>', null, '<title>Daily</title>');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(PageType::ScriptRendered, $a->pageType);
        self::assertTrue($a->hasWarning(Warning::NeedsJavascript));
    }
}
