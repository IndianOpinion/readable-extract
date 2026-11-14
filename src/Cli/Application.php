<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Cli;

use IndianOpinion\ReadableExtract\Exception\FixtureException;
use IndianOpinion\ReadableExtract\Exception\ReadableExtractException;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;
use IndianOpinion\ReadableExtract\Fetch\FetchOptions;
use IndianOpinion\ReadableExtract\Fetch\HttpFetcher;
use IndianOpinion\ReadableExtract\Fetch\Transport;
use IndianOpinion\ReadableExtract\Fixtures\Suite;
use IndianOpinion\ReadableExtract\Scoring\Baseline;
use IndianOpinion\ReadableExtract\Scoring\Scorer;
use IndianOpinion\ReadableExtract\Scoring\ScoreOptions;

/**
 * The bin/readable-extract command line. Exit codes: 0 success, 1
 * regression, 2 usage or fixture error, 3 extraction or fetch error.
 */
final class Application
{
    public const OK = 0;
    public const REGRESSION = 1;
    public const USAGE = 2;
    public const FAILED = 3;

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct($out = null, $err = null, private readonly ?Transport $transport = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /** @param list<string> $argv arguments without the program name */
    public function run(array $argv): int
    {
        [$args, $opts] = $this->parse($argv);
        $command = array_shift($args);
        try {
            return match ($command) {
                'extract' => $this->extract($args, $opts),
                'score' => $this->score($args, $opts),
                'baseline' => $this->baseline($args, $opts),
                'fixture' => $this->fixture($args, $opts),
                null, 'help', '--help' => $this->help(self::OK),
                default => $this->usage('Unknown command: ' . $command),
            };
        } catch (FixtureException $e) {
            $this->write($this->err, 'Fixture error: ' . $e->getMessage() . "\n");
            return self::USAGE;
        } catch (ReadableExtractException $e) {
            $this->write($this->err, (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage() . "\n");
            return self::FAILED;
        }
    }

    /**
     * @param list<string>                $args
     * @param array<string, string|true>  $opts
     */
    private function extract(array $args, array $opts): int
    {
        $options = new ExtractorOptions();
        if (isset($opts['trace'])) {
            $options = $options->withTrace();
        }
        $extractor = new Extractor($options);
        if (isset($opts['fetch'])) {
            $url = is_string($opts['fetch']) ? $opts['fetch'] : ($args[0] ?? null);
            $ua = $opts['user-agent'] ?? null;
            if ($url === null || !is_string($ua)) {
                return $this->usage('extract --fetch <url> --user-agent=<ua> [--json]');
            }
            $page = (new HttpFetcher(new FetchOptions($ua), $this->transport))->fetch($url);
            $article = $extractor->extract($page->body, $page->finalUrl, $page->toContext());
        } else {
            $file = $args[0] ?? null;
            $url = $opts['url'] ?? null;
            if ($file === null || !is_string($url)) {
                return $this->usage('extract <file.html> --url=<url> [--json] [--trace]');
            }
            if (!is_file($file)) {
                $this->write($this->err, 'File not found: ' . $file . "\n");
                return self::USAGE;
            }
            $article = $extractor->extract((string) file_get_contents($file), $url);
        }
        if (isset($opts['json'])) {
            $this->write($this->out, json_encode($article, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            return self::OK;
        }
        $a = $article;
        $lines = [
            'Page type:   ' . $a->pageType->value,
            'Title:       ' . ($a->title ?? '-'),
            'Authors:     ' . (implode(', ', array_map(static fn ($x): string => $x->name . ' (' . $x->kind->value . ')', $a->authors)) ?: '-'),
            'Published:   ' . ($a->publishedAt?->format(DATE_ATOM) ?? '-'),
            'Modified:    ' . ($a->modifiedAt?->format(DATE_ATOM) ?? '-'),
            'Canonical:   ' . ($a->canonicalUrl ?? '-'),
            'Lead image:  ' . ($a->leadImage->url ?? '-'),
            'Language:    ' . ($a->language ?? '-') . ($a->detectedScript !== null ? ' (script ' . $a->detectedScript . ')' : ''),
            'Characters:  ' . $a->charCount . ', words: ' . $a->wordCount . ' (' . $a->wordCountMethod . ')',
            'Confidence:  ' . $a->confidence . ($a->isReadable() ? ' (readable)' : ' (hold for a human)'),
            'Warnings:    ' . (implode(', ', array_map(static fn ($w): string => $w->value, $a->warnings)) ?: '-'),
            '',
            $a->bodyText,
        ];
        $this->write($this->out, implode("\n", $lines) . "\n");
        if ($a->trace !== null) {
            $this->write($this->out, "\n--- trace ---\n" . $a->trace->toText());
        }
        return self::OK;
    }

    /**
     * @param list<string>               $args
     * @param array<string, string|true> $opts
     */
    private function score(array $args, array $opts): int
    {
        $dir = $args[0] ?? 'fixtures';
        $format = is_string($opts['format'] ?? null) ? $opts['format'] : 'text';
        if (!in_array($format, ['text', 'json', 'markdown'], true)) {
            return $this->usage('--format must be text, json or markdown');
        }
        $tolerance = 0.01;
        if (isset($opts['tolerance'])) {
            if (!is_string($opts['tolerance']) || !is_numeric($opts['tolerance'])) {
                return $this->usage('--tolerance must be a number');
            }
            $tolerance = (float) $opts['tolerance'];
        }
        $suite = Suite::load($dir);
        $report = (new Scorer(new Extractor(), new ScoreOptions(tolerance: $tolerance)))->score($suite);
        $baselineFile = is_string($opts['baseline'] ?? null) ? $opts['baseline'] : $dir . '/baseline.json';
        $comparison = null;
        if (isset($opts['baseline']) || is_file($baselineFile)) {
            $comparison = $report->compare(Baseline::fromFile($baselineFile), $tolerance);
        }
        $output = match ($format) {
            'json' => json_encode(
                $report->toArray() + ['comparison' => $comparison?->toArray()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n",
            'markdown' => $report->toMarkdown($comparison),
            default => $report->toText($comparison),
        };
        $this->write($this->out, $output);
        return $comparison !== null && $comparison->isRegression() ? self::REGRESSION : self::OK;
    }

    /**
     * @param list<string>               $args
     * @param array<string, string|true> $opts
     */
    private function baseline(array $args, array $opts): int
    {
        if (!isset($opts['write'])) {
            return $this->usage('baseline --write [<fixtures-dir>]');
        }
        $dir = $args[0] ?? (is_string($opts['write']) ? $opts['write'] : 'fixtures');
        $report = (new Scorer(new Extractor()))->score(Suite::load($dir));
        $file = $dir . '/baseline.json';
        if (file_put_contents($file, Baseline::fromReport($report)->toJson()) === false) {
            $this->write($this->err, 'Cannot write ' . $file . "\n");
            return self::USAGE;
        }
        $this->write($this->out, 'Baseline written: ' . $file . ' (' . count($report->results) . " fixtures)\n");
        return self::OK;
    }

    /**
     * @param list<string>               $args
     * @param array<string, string|true> $opts
     */
    private function fixture(array $args, array $opts): int
    {
        $name = is_string($opts['new'] ?? null) ? $opts['new'] : ($args[0] ?? null);
        if (!isset($opts['new']) || $name === null || preg_match('/^[a-z0-9][a-z0-9\-]{1,80}$/', $name) !== 1) {
            return $this->usage('fixture --new <name> [--dir=fixtures]  (name: lowercase letters, digits, hyphens)');
        }
        $base = is_string($opts['dir'] ?? null) ? $opts['dir'] : 'fixtures';
        $path = $base . '/' . $name;
        if (file_exists($path)) {
            $this->write($this->err, 'Already exists: ' . $path . "\n");
            return self::USAGE;
        }
        if (!mkdir($path, 0775, true)) {
            $this->write($this->err, 'Cannot create ' . $path . "\n");
            return self::USAGE;
        }
        $meta = [
            'url' => 'https://news.example/section/' . $name,
            'kind' => 'synthetic',
            'label' => 'describe the kind of outlet, never its name (for example "regional daily, Hindi")',
            'pattern' => 'the page pattern this fixture exercises',
            'date' => gmdate('Y-m-d'),
            'script' => 'Latn',
            'layout' => 'article',
            'covers' => [],
            'termsCheckedOn' => 'captured fixtures only: YYYY-MM-DD the terms were read; delete for synthetic',
            'termsNote' => 'captured fixtures only: one line on what the terms allow; delete for synthetic',
        ];
        $expected = ['pageType' => 'article', 'title' => '', 'authors' => [], 'publishedAt' => null, 'bodyMustContain' => []];
        file_put_contents($path . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($path . '/expected.json', json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($path . '/page.html', "<!doctype html>\n<html lang=\"en\">\n<head><title></title></head>\n<body>\n</body>\n</html>\n");
        $this->write($this->out, 'Fixture skeleton written: ' . $path . "\nFill in page.html (reserved example hosts only, no images), expected.json and meta.json.\n");
        return self::OK;
    }

    private function help(int $code): int
    {
        $this->write($code === self::OK ? $this->out : $this->err, <<<TXT
readable-extract: article extraction with honest confidence

  extract <file.html> --url=<url> [--json] [--trace]   offline extraction
  extract --fetch <url> --user-agent=<ua> [--json]     fetch (robots.txt honoured), then extract
  score [<fixtures-dir>] [--format=text|json|markdown] [--baseline=<file>] [--tolerance=0.01]
  baseline --write [<fixtures-dir>]                    rewrite the committed baseline
  fixture --new <name> [--dir=fixtures]                write an empty fixture skeleton

Exit codes: 0 ok, 1 regression, 2 usage or fixture error, 3 extraction or fetch error.

TXT);
        return $code;
    }

    private function usage(string $message): int
    {
        $this->write($this->err, 'Usage: ' . $message . "\n");
        return self::USAGE;
    }

    /**
     * @param list<string> $argv
     * @return array{0: list<string>, 1: array<string, string|true>}
     */
    private function parse(array $argv): array
    {
        $args = [];
        $opts = [];
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $a = $argv[$i];
            if (str_starts_with($a, '--')) {
                $eq = strpos($a, '=');
                if ($eq !== false) {
                    $opts[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
                } else {
                    $key = substr($a, 2);
                    $takesValue = in_array($key, ['fetch', 'new'], true);
                    if ($takesValue && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                        $opts[$key] = $argv[++$i];
                    } else {
                        $opts[$key] = true;
                    }
                }
            } else {
                $args[] = $a;
            }
        }
        return [$args, $opts];
    }

    /** @param resource $stream */
    private function write($stream, string $s): void
    {
        fwrite($stream, $s);
    }
}
