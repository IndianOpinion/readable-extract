<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Internal\Dom;
use IndianOpinion\ReadableExtract\Internal\HtmlParser;
use IndianOpinion\ReadableExtract\Internal\ImagePass;
use IndianOpinion\ReadableExtract\Internal\ImageRules;
use IndianOpinion\ReadableExtract\Source;
use IndianOpinion\ReadableExtract\Tests\Support\NoNetwork;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRD cases 31 to 38. */
final class ImageTest extends TestCase
{
    private function rules(): ImageRules
    {
        return new ImageRules(new ExtractorOptions());
    }

    private function choose(string $imgHtml): ?string
    {
        $body = HtmlParser::fragment($imgHtml);
        $img = Dom::byTag($body, 'img')[0];
        return $this->rules()->chooseSource($img, Page::URL)['url'];
    }

    public function testCase31LazyAttributeBeatsSpacerSrc(): void
    {
        self::assertSame(
            'https://img.daily.example/2026/real-photo.jpg',
            $this->choose('<img src="https://static.daily.example/spacer.gif" data-src="https://img.daily.example/2026/real-photo.jpg">'),
        );
        self::assertSame('https://www.daily.example/2026/rel.jpg', $this->choose('<img src="https://static.daily.example/blank.gif" data-lazy-src="/2026/rel.jpg">'));
    }

    public function testCase31DensityBeatsWidth(): void
    {
        self::assertSame(
            'https://img.daily.example/tram-2x.jpg',
            $this->choose('<img src="https://img.daily.example/tram-small.jpg" srcset="https://img.daily.example/tram-1200.jpg 1200w, https://img.daily.example/tram-2x.jpg 2x">'),
        );
        self::assertSame(
            'https://img.daily.example/tram-1600.jpg',
            $this->choose('<img srcset="https://img.daily.example/tram-800.jpg 800w, https://img.daily.example/tram-1600.jpg 1600w">'),
        );
    }

    public function testCase31NoscriptImageUsedWhenNothingElsePasses(): void
    {
        $html = Page::article('<figure><img src="data:image/gif;base64,R0lGOD" class="lazy"><noscript><img src="https://img.daily.example/lake.jpg"></noscript></figure>' . Page::prose());
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('https://img.daily.example/lake.jpg', $a->leadImage?->url);
        self::assertStringContainsString('<img src="https://img.daily.example/lake.jpg">', $a->bodyHtml);
    }

    public function testCase31AttributeListIsConfigurable(): void
    {
        $rules = new ImageRules((new ExtractorOptions())->withLazyAttributes(['data-big']));
        $img = Dom::byTag(HtmlParser::fragment('<img src="https://img.daily.example/spacer.gif" data-big="https://img.daily.example/big.jpg">'), 'img')[0];
        self::assertSame('https://img.daily.example/big.jpg', $rules->chooseSource($img, Page::URL)['url']);
    }

    #[DataProvider('placeholderNames')]
    public function testCase32PlaceholderNamesMatchWholeNames(string $url, bool $placeholder): void
    {
        self::assertSame($placeholder, $this->rules()->rejectReason($url) === 'placeholder', $url);
    }

    /** @return array<string, array{string, bool}> */
    public static function placeholderNames(): array
    {
        return [
            'pixel.gif' => ['https://img.daily.example/pixel.gif', true],
            '1x1_spacer.png' => ['https://img.daily.example/1x1_spacer.png', true],
            'blank-2.gif' => ['https://img.daily.example/blank-2.gif', true],
            'no-image.png' => ['https://img.daily.example/no-image.png', true],
            'phone-pixel-10.jpg' => ['https://img.daily.example/phone-pixel-10.jpg', false],
            'dotcom-launch.jpg' => ['https://img.daily.example/dotcom-launch.jpg', false],
        ];
    }

    public function testCase32DataAndNonHttpAreRejected(): void
    {
        self::assertNotNull($this->rules()->rejectReason('data:image/png;base64,AAAA'));
        self::assertNotNull($this->rules()->rejectReason('ftp://img.daily.example/a.jpg'));
        self::assertNotNull($this->rules()->rejectReason(''));
    }

    public function testCase33SmallTagIsFurnitureWhateverItsUrl(): void
    {
        self::assertNull($this->choose('<img src="https://img.daily.example/2026/11/ward-office.jpg" width="19" height="23">'));
        self::assertSame('https://img.daily.example/2026/11/ward-office.jpg', $this->choose('<img src="https://img.daily.example/2026/11/ward-office.jpg" width="800" height="450">'));
    }

    #[DataProvider('urlSizes')]
    public function testCase34ExplicitUrlSizes(string $url, bool $furniture): void
    {
        self::assertSame($furniture, $this->rules()->rejectReason($url) === 'url_size', $url);
    }

    /** @return array<string, array{string, bool}> */
    public static function urlSizes(): array
    {
        return [
            'size=50:50' => ['https://img.daily.example/thumb.jpg?size=50:50', true],
            'w=80' => ['https://img.daily.example/photo.jpg?w=80', true],
            'width-100' => ['https://img.daily.example/width-100/photo.jpg', true],
            'SQUARE_80' => ['https://img.daily.example/SQUARE_80/photo.jpg', true],
            '150x150 suffix' => ['https://img.daily.example/2026/photo-150x150.jpg', true],
            'numeric id' => ['https://img.daily.example/photo/1234567/', false],
            'large w' => ['https://img.daily.example/photo.jpg?w=1200', false],
        ];
    }

    #[DataProvider('chromeUrls')]
    public function testCase35ChromeIsFurniture(string $url): void
    {
        self::assertContains($this->rules()->rejectReason($url), ['chrome_name', 'chrome_extension'], $url);
    }

    /** @return array<string, array{string}> */
    public static function chromeUrls(): array
    {
        return [
            'logo' => ['https://static.daily.example/assets/logo.png'],
            'svg' => ['https://static.daily.example/icon.svg'],
            'share-btn' => ['https://static.daily.example/share-btn.png'],
            'avatar dir' => ['https://static.daily.example/avatar/u1.jpg'],
            'ico' => ['https://static.daily.example/favicon.ico'],
        ];
    }

    public function testCase36ImagePassIsIdempotent(): void
    {
        $o = new ExtractorOptions();
        $pass = new ImagePass(new ImageRules($o), $o->lazyAttributes());
        $html = '<p><img src="https://static.daily.example/spacer.gif" data-src="https://img.daily.example/a.jpg" width="800" height="600"></p>'
            . '<p><img src="https://img.daily.example/b.jpg" srcset="https://img.daily.example/b-2x.jpg 2x"></p>'
            . '<p><img src="https://static.daily.example/logo.png"></p><p><img src="/rel/c.jpg" alt="C"></p>';
        $root = HtmlParser::fragment($html);
        $pass->apply($root, Page::URL);
        $once = $root->ownerDocument?->saveHTML($root);
        $pass->apply($root, Page::URL);
        $twice = $root->ownerDocument?->saveHTML($root);
        self::assertSame($once, $twice);
        self::assertStringContainsString('src="https://img.daily.example/a.jpg"', (string) $once);
        self::assertStringNotContainsString('logo.png', (string) $once);
    }

    public function testCase37OgSpacerSkippedJsonLdChosen(): void
    {
        $html = Page::article(Page::prose(), ['image' => ['@type' => 'ImageObject', 'url' => 'https://img.daily.example/lead.jpg', 'width' => 1200, 'height' => 675]], '<meta property="og:image" content="https://static.daily.example/pixel.gif">');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('https://img.daily.example/lead.jpg', $a->leadImage?->url);
        self::assertSame(1200, $a->leadImage?->width);
        self::assertSame(675, $a->leadImage?->height);
        self::assertSame(Source::JsonLd, $a->leadImage?->source);
    }

    public function testCase37JsonLdListPicksLargest(): void
    {
        $html = Page::article(Page::prose(), ['image' => [
            ['@type' => 'ImageObject', 'url' => 'https://img.daily.example/small.jpg', 'width' => 400, 'height' => 300],
            ['@type' => 'ImageObject', 'url' => 'https://img.daily.example/big.jpg', 'width' => 1600, 'height' => 900],
        ]]);
        self::assertSame('https://img.daily.example/big.jpg', (new Extractor())->extract($html, Page::URL)->leadImage?->url);
    }

    public function testCase37WithoutJsonLdFirstPassingBodyImage(): void
    {
        $html = Page::html('<main><article>' . Page::prose(300) . '<img src="https://static.daily.example/logo.png"><img src="https://img.daily.example/first-real.jpg">' . Page::prose(300, 1) . '<img src="https://img.daily.example/second.jpg"></article></main>', null, '<meta property="og:image" content="https://static.daily.example/spacer.gif">');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('https://img.daily.example/first-real.jpg', $a->leadImage?->url);
        self::assertSame(Source::Visible, $a->leadImage?->source);
    }

    public function testCase38OnlyFurnitureGivesNullAndNoNetwork(): void
    {
        $html = Page::article(Page::prose() . '<p><img src="https://static.daily.example/1x1_spacer.png"><img src="https://img.daily.example/x.jpg" width="19" height="23"></p>', [], '<meta property="og:image" content="https://static.daily.example/assets/logo.png">');
        NoNetwork::install();
        try {
            $a = (new Extractor())->extract($html, Page::URL);
        } finally {
            NoNetwork::uninstall();
        }
        self::assertNull($a->leadImage);
        self::assertTrue($a->hasWarning(Warning::NoLeadImage));
        self::assertSame([], NoNetwork::$attempts);
    }
}
