<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\PageType;
use IndianOpinion\ReadableExtract\Tests\Support\NoNetwork;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

/** PRD cases 10 to 13. */
final class ParsingTest extends TestCase
{
    public function testCase10HugeBodyWithPathologicalPatternExtractsFully(): void
    {
        // One 4 MB paragraph of a pattern that defeats naive backtracking regexes.
        $chunk = str_repeat('a', 50) . ' "' . str_repeat('b ', 20) . '" ';
        $body = str_repeat($chunk, (int) ceil(4 * 1024 * 1024 / strlen($chunk)));
        $html = '<html><body><article><p>' . $body . '</p></article></body></html>';
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(PageType::Article, $a->pageType);
        self::assertSame(mb_strlen(trim(preg_replace('/\s+/', ' ', $body) ?? '')), $a->charCount);
    }

    public function testCase11MalformedHtmlStillParses(): void
    {
        $html = '<html><body><section class="story-content"><p>' . str_repeat('Unclosed paragraph with enough words to score well, and commas, too. ', 4)
            . '<p>' . str_repeat('Nested paragraph without a close, still part of the story body. ', 4)
            . '</div></div><p>' . str_repeat('After a stray closing div the text continues as before. ', 4)
            . '</section></body>';
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertStringContainsString('Unclosed paragraph', $a->bodyText);
        self::assertStringContainsString('After a stray closing div', $a->bodyText);
        self::assertGreaterThanOrEqual(400, $a->charCount);
    }

    public function testCase12RemoteDtdTriggersNoNetworkAccess(): void
    {
        $transport = new ScriptedTransport();
        NoNetwork::install();
        try {
            $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">'
                . '<!DOCTYPE x [<!ENTITY ext SYSTEM "http://evil.example/x">]>'
                . Page::article(Page::prose() . '<p>&ext; and more text to make a full paragraph here.</p>');
            $a = (new Extractor())->extract($html, Page::URL);
        } finally {
            NoNetwork::uninstall();
        }
        self::assertSame([], NoNetwork::$attempts);
        self::assertSame([], $transport->requests);
        self::assertStringNotContainsString('evil', $a->bodyHtml);
    }

    public function testCase13NavAsideFooterAndHiddenNeverInBody(): void
    {
        $html = Page::html('<nav>NAVTEXT menu items here for the site</nav><main><article class="story">'
            . Page::prose(800)
            . '<aside>ASIDETEXT related reading list for later</aside>'
            . '<div aria-hidden="true"><p>HIDDENTEXT that a screen reader also skips over here.</p></div>'
            . '<div hidden><p>HIDDENATTR text that is not shown to anyone at all.</p></div>'
            . '<script>var SCRIPTTEXT = 1;</script><style>.x{}</style><form><p>FORMTEXT inside a form</p></form>'
            . '</article></main><footer>FOOTERTEXT copyright line</footer>');
        $a = (new Extractor())->extract($html, Page::URL);
        foreach (['NAVTEXT', 'ASIDETEXT', 'HIDDENTEXT', 'HIDDENATTR', 'SCRIPTTEXT', 'FORMTEXT', 'FOOTERTEXT'] as $needle) {
            self::assertStringNotContainsString($needle, $a->bodyText, $needle);
        }
        self::assertStringContainsString('drainage plan', $a->bodyText);
    }
}
