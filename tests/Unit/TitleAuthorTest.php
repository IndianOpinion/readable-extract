<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Unit;

use IndianOpinion\ReadableExtract\Author;
use IndianOpinion\ReadableExtract\AuthorKind;
use IndianOpinion\ReadableExtract\AuthorRole;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Internal\TitleRules;
use IndianOpinion\ReadableExtract\Source;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\TestCase;

/** PRD cases 39 to 46. */
final class TitleAuthorTest extends TestCase
{
    /** @param list<Author> $authors @return list<string> */
    private function names(array $authors): array
    {
        return array_map(static fn (Author $a): string => $a->name, $authors);
    }

    public function testCase39JsonLdHeadlineBeatsTitleElement(): void
    {
        $html = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'Headline from structured data'], '<title>Other title | Daily</title><meta property="og:title" content="Open Graph title">');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame('Headline from structured data', $a->title);
        self::assertSame(Source::JsonLd, $a->fieldSources['title']);
    }

    public function testCase39OrderFallsThroughToTitleElement(): void
    {
        $html = '<html><head><title>Only the title element here</title></head><body>' . Page::prose() . '</body></html>';
        self::assertSame('Only the title element here', (new Extractor())->extract($html, Page::URL)->title);
        $h1 = '<html><head><title>Title element</title></head><body><h1>The single heading</h1>' . Page::prose() . '</body></html>';
        self::assertSame('The single heading', (new Extractor())->extract($h1, Page::URL)->title);
    }

    public function testCase40SiteSuffixStrippedButShortRemainderKept(): void
    {
        self::assertSame('Monsoon session opens with farm bill debate', TitleRules::strip('Monsoon session opens with farm bill debate | Daily Site', 'Daily Site', 'www.daily.example'));
        self::assertSame('Monsoon session opens with farm bill debate', TitleRules::strip('Monsoon session opens with farm bill debate - Daily Site News', 'Daily Site', null));
        self::assertSame('Monsoon session opens with farm bill debate', TitleRules::strip('Daily: Monsoon session opens with farm bill debate', null, 'daily.example'));
        self::assertSame('Site: Budget', TitleRules::strip('Site: Budget', 'Site', null));
        self::assertSame('Story | Site', TitleRules::strip('Story | Site', 'Site', null), 'a one-word remainder is never left');
        self::assertSame('Rain: what to expect this week in the city', TitleRules::strip('Rain: what to expect this week in the city', 'Daily Site', 'daily.example'), 'a prefix that is not the site stays');
    }

    public function testCase41TitleEntitiesAndWhitespaceNormalised(): void
    {
        $html = '<html><head><title>  Rates   &amp;  fares&nbsp;rise' . "\t\n" . ' again  </title></head><body>' . Page::prose() . '</body></html>';
        self::assertSame('Rates & fares rise again', (new Extractor())->extract($html, Page::URL)->title);
        $caps = '<html><head><title>BREAKING: COUNCIL MEETS TODAY</title></head><body>' . Page::prose() . '</body></html>';
        self::assertSame('BREAKING: COUNCIL MEETS TODAY', (new Extractor())->extract($caps, Page::URL)->title);
    }

    public function testCase42JsonLdAuthorListBeatsByline(): void
    {
        $html = Page::html('<div class="byline">By Someone Else</div>' . Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'H', 'author' => [
            ['@type' => 'Person', 'name' => 'Asha Rao', 'url' => 'https://www.daily.example/author/asha'],
            ['@type' => 'Person', 'name' => 'Vikram Sen'],
        ]]);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(['Asha Rao', 'Vikram Sen'], $this->names($a->authors));
        self::assertSame('https://www.daily.example/author/asha', $a->authors[0]->url);
        self::assertSame(Source::JsonLd, $a->fieldSources['authors']);
    }

    public function testCase43AgenciesAndOrganisations(): void
    {
        $html = Page::html('<div class="byline">By Web Desk and News Service</div>' . Page::prose());
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(['Web Desk', 'News Service'], $this->names($a->authors));
        self::assertSame(AuthorKind::Agency, $a->authors[0]->kind);
        self::assertSame(AuthorKind::Agency, $a->authors[1]->kind);
        $org = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'H', 'author' => ['@type' => 'Organization', 'name' => 'Daily Media Group']]);
        self::assertSame(AuthorKind::Organisation, (new Extractor())->extract($org, Page::URL)->authors[0]->kind);
    }

    public function testCase43AgencyListIsConfigurable(): void
    {
        $o = (new ExtractorOptions())->withAgencyPatterns(['newsroom']);
        $a = (new Extractor($o))->extract(Page::html('<div class="byline">By City Newsroom</div>' . Page::prose()), Page::URL);
        self::assertSame(AuthorKind::Agency, $a->authors[0]->kind);
    }

    public function testCase44BylineSplitsAuthorsAndEditors(): void
    {
        $html = Page::html('<p class="byline">By Ravi Menon and Leela Pillai, Edited by Joseph Kurian</p>' . Page::prose());
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(['Ravi Menon', 'Leela Pillai'], $this->names($a->authors));
        self::assertSame(['Joseph Kurian'], $this->names($a->editors));
        self::assertSame(AuthorRole::Editor, $a->editors[0]->role);
    }

    public function testCase44HindiBylineSplitsOnAur(): void
    {
        $html = Page::html('<div class="author-name">सुनीता वर्मा और अमित सिंह</div>' . Page::prose(), null, '', 'hi');
        self::assertSame(['सुनीता वर्मा', 'अमित सिंह'], $this->names((new Extractor())->extract($html, Page::URL)->authors));
    }

    public function testCase45DuplicatesMergeEmailDropped(): void
    {
        $html = Page::html(Page::prose(), ['@type' => 'NewsArticle', 'headline' => 'H', 'author' => [
            ['@type' => 'Person', 'name' => 'Asha Rao'], ['@type' => 'Person', 'name' => 'asha  rao'],
            ['@type' => 'Person', 'name' => 'desk@daily.example'], ['@type' => 'Person', 'name' => 'https://www.daily.example/author/x'],
        ]]);
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame(['Asha Rao'], $this->names($a->authors));
        self::assertTrue($a->hasWarning(Warning::BadAuthor));
    }

    public function testCase46NoBylineGivesEmptyListNotSiteName(): void
    {
        $html = Page::html(Page::prose(), null, '<meta property="og:site_name" content="Daily Site">');
        $a = (new Extractor())->extract($html, Page::URL);
        self::assertSame([], $a->authors);
        self::assertSame('Daily Site', $a->siteName);
        self::assertSame(Source::None, $a->fieldSources['authors']);
    }
}
