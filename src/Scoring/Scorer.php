<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Scoring;

use IndianOpinion\ReadableExtract\Article;
use IndianOpinion\ReadableExtract\Author;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\Fixtures\Fixture;
use IndianOpinion\ReadableExtract\Fixtures\Suite;

/**
 * Runs the extractor over every fixture and scores each expected field
 * (case 85). Offline: fixtures are read from disk and nothing is fetched.
 */
final class Scorer
{
    public const FIELDS = ['pageType', 'title', 'authors', 'publishedAt', 'modifiedAt', 'leadImage', 'canonicalUrl', 'language', 'body'];

    private ScoreOptions $o;

    public function __construct(private readonly Extractor $extractor, ?ScoreOptions $options = null)
    {
        $this->o = $options ?? new ScoreOptions();
    }

    public function score(Suite $suite): Report
    {
        $results = [];
        foreach ($suite->fixtures as $fixture) {
            $article = $this->extractor->extract($fixture->html, $fixture->url());
            $results[] = $this->scoreOne($fixture, $article);
        }
        return new Report($results);
    }

    public function scoreOne(Fixture $fixture, Article $a): FixtureResult
    {
        $e = $fixture->expected;
        $fields = [];
        $bodyF1 = null;
        if (array_key_exists('pageType', $e)) {
            $fields['pageType'] = $this->exact('pageType', $e['pageType'], $a->pageType->value);
        }
        if (array_key_exists('title', $e)) {
            $exp = is_string($e['title']) ? Metrics::normaliseText($e['title']) : null;
            $act = $a->title !== null ? Metrics::normaliseText($a->title) : null;
            $fields['title'] = new FieldResult('title', $exp === $act, $exp === $act ? 1.0 : 0.0, ['expected' => $e['title'], 'actual' => $a->title]);
        }
        if (array_key_exists('authors', $e)) {
            $exp = is_array($e['authors']) ? array_values(array_map('strval', $e['authors'])) : [];
            $act = array_map(static fn (Author $x): string => $x->name, $a->authors);
            $pr = Metrics::setPrecisionRecall($exp, $act);
            $pass = $pr['precision'] === 1.0 && $pr['recall'] === 1.0;
            $fields['authors'] = new FieldResult('authors', $pass, ($pr['precision'] + $pr['recall']) / 2, $pr + ['actual' => implode('; ', $act)]);
        }
        foreach (['publishedAt' => $a->publishedAt, 'modifiedAt' => $a->modifiedAt] as $k => $actual) {
            if (array_key_exists($k, $e)) {
                $exp = is_string($e[$k]) ? $e[$k] : null;
                $ok = Metrics::datesMatch($exp, $actual, $this->o->dateTolerance);
                $fields[$k] = new FieldResult($k, $ok, $ok ? 1.0 : 0.0, ['expected' => $exp, 'actual' => $actual?->format(DATE_ATOM)]);
            }
        }
        if (array_key_exists('leadImage', $e)) {
            $exp = Metrics::normaliseUrl(is_string($e['leadImage']) ? $e['leadImage'] : null);
            $act = Metrics::normaliseUrl($a->leadImage?->url);
            $fields['leadImage'] = new FieldResult('leadImage', $exp === $act, $exp === $act ? 1.0 : 0.0, ['expected' => $exp, 'actual' => $act]);
        }
        if (array_key_exists('canonicalUrl', $e)) {
            $fields['canonicalUrl'] = $this->exact('canonicalUrl', $e['canonicalUrl'], $a->canonicalUrl);
        }
        if (array_key_exists('language', $e)) {
            $fields['language'] = $this->exact('language', $e['language'], $a->language);
        }
        $hasBody = array_key_exists('bodyText', $e) || isset($e['bodyMustContain']) || isset($e['bodyMustNotContain']);
        if ($hasBody) {
            $pass = true;
            $detail = [];
            $score = 1.0;
            if (array_key_exists('bodyText', $e)) {
                $f1 = Metrics::tokenF1(is_string($e['bodyText']) ? $e['bodyText'] : '', $a->bodyText);
                $bodyF1 = $f1['f1'];
                $score = $f1['f1'];
                $pass = $f1['f1'] >= $this->o->bodyPassF1;
                $detail += ['precision' => round($f1['precision'], 4), 'recall' => round($f1['recall'], 4), 'f1' => round($f1['f1'], 4)];
            }
            $text = Metrics::normaliseText($a->bodyText);
            foreach ((array) ($e['bodyMustContain'] ?? []) as $needle) {
                if (!str_contains($text, Metrics::normaliseText((string) $needle))) {
                    $pass = false;
                    $detail['missing'] = (string) $needle;
                }
            }
            foreach ((array) ($e['bodyMustNotContain'] ?? []) as $needle) {
                $n = Metrics::normaliseText((string) $needle);
                if ($n !== '' && str_contains($text, $n)) {
                    $pass = false;
                    $detail['unwanted'] = (string) $needle;
                }
            }
            $fields['body'] = new FieldResult('body', $pass, $score, $detail);
        }
        return new FixtureResult($fixture->name, $fields, $bodyF1);
    }

    private function exact(string $field, mixed $expected, ?string $actual): FieldResult
    {
        $exp = is_string($expected) ? $expected : null;
        $ok = $exp === $actual;
        return new FieldResult($field, $ok, $ok ? 1.0 : 0.0, ['expected' => $exp, 'actual' => $actual]);
    }
}
