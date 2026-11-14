# readable-extract

Extracts the article body, title, authors, dates and lead image from a news page in plain PHP, and says where each field came from and how sure it is. It treats Indic scripts as first-class, refuses to pass off a biography, a playlist or a section front as an article, and ships a scored fixture suite so a regression fails CI.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured in production before this library existed: one importer feed stored an author biography in place of the article for about 25 items in four days.

- PHP 8.1, 8.2, 8.3. Zero runtime Composer dependencies.
- Needs `ext-dom`, `ext-libxml`, `ext-mbstring`. `ext-curl` only for the default fetch transport. `ext-intl` is optional (NFC, IDN hosts, word breaks for Thai and CJK).
- Extraction is offline. Fetching happens only when you call the fetcher.

## Install

With Composer:

```sh
composer require indianopinion/readable-extract
```

Without Composer:

```php
require 'path/to/readable-extract/bootstrap.php';
```

`bootstrap.php` registers an autoloader for `IndianOpinion\ReadableExtract` and does nothing else.

## Quick start

You already hold the HTML (from your importer or a cache):

```php
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\ExtractorOptions;

$extractor = new Extractor((new ExtractorOptions())->withOwnHosts(['www.my-site.example']));
$article = $extractor->extract($html, 'https://www.daily.example/city/story-1001');

if (!$article->isReadable()) {
    // Not an article, too short, or not confident enough: hold it for a human.
}
echo $article->title, "\n", $article->bodyText;
var_dump($article->warnings, $article->fieldSources, $article->confidence);
```

Fetch first, only when you mean to (robots.txt is always honoured):

```php
use IndianOpinion\ReadableExtract\Fetch\FetchOptions;
use IndianOpinion\ReadableExtract\Fetch\HttpFetcher;

$fetcher = new HttpFetcher(new FetchOptions('YourProduct/1.0 (+https://your.site/contact)'));
$page = $fetcher->fetch('https://www.daily.example/city/story-1001');
$article = (new Extractor())->extract($page->body, $page->finalUrl, $page->toContext());
```

`ReadableExtract::fromUrl($url, $fetchOptions)` does both in one call, with a fresh fetcher each time. Reuse one `HttpFetcher` when you fetch more than one page so the robots cache and the rate limiter carry over.

Command line:

```sh
bin/readable-extract extract page.html --url=https://www.daily.example/a [--json] [--trace]
bin/readable-extract extract --fetch https://www.daily.example/a --user-agent="YourProduct/1.0 (+https://your.site)"
bin/readable-extract score fixtures [--format=text|json|markdown] [--baseline=file] [--tolerance=0.01]
bin/readable-extract baseline --write fixtures
bin/readable-extract fixture --new my-case [--dir=fixtures]
```

Exit codes: 0 ok, 1 regression, 2 usage or fixture error, 3 extraction or fetch error.

More in `examples/`: offline extraction, fetch then extract, scoring, and a WordPress import hook sketch.

## What you get

`Article` is an immutable value object (`JsonSerializable`, `toArray()`):

| Field | Meaning |
|---|---|
| `pageType` | `article`, `liveblog`, `video`, `gallery`, `index`, `paywalled`, `script_rendered`, `wrapper`, `empty`, `unknown` |
| `title` | Site-name suffix or prefix stripped when it matches the site |
| `bodyHtml`, `bodyText` | Allowlisted HTML and plain text; empty for video, gallery, index and refused pages |
| `charCount`, `wordCount`, `wordCountMethod` | Characters after whitespace collapse; words by `whitespace`, `intl` or `characters` |
| `authors`, `editors` | `Author` entries: `name`, `url`, `kind` (`person`, `organisation`, `agency`), `role` |
| `publishedAt`, `modifiedAt` | `DateTimeImmutable` in UTC, or null. Never "now" |
| `leadImage` | `Image` (`url`, `width`, `height`, `source`) or null. Never downloaded |
| `canonicalUrl`, `ampUrl`, `host` | Canonical without tracking parameters or fragment; host without mirror labels |
| `language`, `detectedScript` | BCP 47 tag as declared; ISO 15924 code of the body's main script |
| `siteName`, `accessibleForFree`, `sponsoredSignals` | Reported, not acted on |
| `warnings` | List of `Warning` values, for example `visible_body_short`, `biography_only`, `not_an_article` |
| `fieldSources`, `fieldConfidence` | Per field: `json_ld`, `microdata`, `meta`, `visible` or `none`, and 0 to 1 |
| `confidence` | Overall, 0 to 1 (formula below) |
| `trace` | With `withTrace()`: every candidate per field, its source, score and why it lost |

`isReadable()` is true when the page type is `article` or `liveblog`, the body meets the prose minimum, and the confidence is at or above the readable bar. Hold anything else for a human.

## Configuration

All options are immutable `with*()` methods on `ExtractorOptions` and `FetchOptions`.

| Option | Default | Notes |
|---|---|---|
| `withProseMinimum` | 400 characters | A body under this is short |
| `withReadableBar` | 0.5 | `isReadable()` threshold |
| `withInputCap` | 5 MB | HTML beyond is cut on a character boundary |
| `withLinkDensityIndex` | 0.5 | Above this with a short body: `index` |
| `withBodyAgreement` | 0.3 | Word-pair overlap under this: `body_disagreement` |
| `withFurnitureBar` | 200 x 120 px | Smaller declared images are furniture |
| `withLazyAttributes` | `data-src`, `data-lazy-src`, `data-original`, `data-hi-res-src`, `data-image`, `data-url` | Ordered |
| `withPlaceholderNames`, `withChromeWords` | spacer, blank, pixel ...; logo, icon, avatar ... | Whole-name and whole-part matches only |
| `withPositiveHints`, `withNegativeHints` | article, story ...; comment, related, share ... | Class and id hints for scoring |
| `withNonArticlePathHints` | `/video/`, `/photos/`, `/web-stories/`, `/tag/` ... | Segment to `video`, `gallery` or `index` |
| `withAgencyPatterns` | news service, desk, bureau, agencies ... | Generic forms only; no outlet list |
| `withBiographyPatterns` | is a, writes on, covers ... | Phrases after a name in a biography |
| `withDefaultTimezone` | `Asia/Kolkata` | For dates without an offset |
| `withFutureTolerance` | 86400 s | Later dates are implausible |
| `withTrackingParams` | `utm_*`, `fbclid`, `gclid` and more | Stripped from the canonical URL |
| `withOwnHosts` | none | Your hosts; never reported as a source |
| `withHostNormaliser` | strip `www`, `amp`, `m`, `mobile` | Any `callable(string): string` |
| `withWrapperHosts` | the main news aggregator's article paths | Wrapper links are refused, not resolved |
| `withAlsoReadPhrases`, `withBoilerplatePhrases`, `withSponsoredLabels` | English and Hindi defaults | In-body clean-up and signals |
| `withScoringWeights` | `ScoringWeights::DEFAULTS` | Any subset; unknown keys are refused |
| `withIntl` | true | false behaves as if ext-intl were missing |
| `withTrace` | false | Per-field candidate trace |

| Fetch option | Default | Notes |
|---|---|---|
| `new FetchOptions($userAgent)` | required | Must name your product and a contact URL or address; `Mozilla/...` is refused |
| robots product token | first token of the user agent | Second constructor argument overrides |
| `withHostInterval` | 10 s | One request per host per interval |
| `withMaxCrawlDelay` | 60 s | A longer `Crawl-delay` refuses the host |
| `withWaitBudget` | 0 s | How long `fetch()` may sleep for a slot |
| `withDefaultBackoff` | 600 s | After 429 without `Retry-After` |
| `withRobotsTtl` | 24 h | robots.txt cache lifetime |
| `withTimeouts` | 5 s connect, 20 s total | The total covers the whole fetch, every hop included |
| `withBodyCap` | 5 MB | The read stops there; warning `body_truncated` |
| `withMaxRedirects` | 5 | 0 to 10, every hop checked |
| `withAllowedPorts` | 80, 443 | |
| `withAllowPrivateAddresses` | false | For tests against a local server only |
| `withHonourRobotsMeta` | none, noarchive, nosnippet, noai, noimageai | You may add, not remove none or noarchive |
| `withAuthorization` | none | Sent only when set, for a source you are licensed to fetch, and only to that URL's own origin (never across a redirect to another host or to http) |
| `withResolver`, `withClock` | system DNS and clock | For tests |

## How it works

1. **Decode.** The charset comes from a byte-order mark, your `Content-Type`, `<meta charset>`, `<meta http-equiv>`, then UTF-8. A page declared Latin-1 that is valid UTF-8 is read as UTF-8 (`charset_mismatch`). Invalid bytes become U+FFFD and are counted. Text is NFC when ext-intl is loaded.
2. **Parse.** libxml's HTML parser builds a DOM. It loads no DTDs or entities and touches no network. Errors are counted, never silenced with `@`. No field is found by running a regular expression over the whole page.
3. **Read structured data.** JSON-LD, microdata and meta tags are read before anything is removed.
4. **Classify.** JSON-LD `@type`, then `og:type`, then URL path hints, then link density. Video, gallery and index pages keep their title and image but return no body.
5. **Find the body.** A scoring walk rates containers by paragraphs, length, commas and sentence marks (the danda counts), link density and class hints. The visible body wins when it meets the prose minimum. Otherwise a structured body (microdata or JSON-LD `articleBody`) that meets it wins. A lone biography never wins. Two long bodies that disagree are never merged: the visible one wins and confidence drops.
6. **Clean.** "Also read" blocks, related lists, share bars, newsletter boxes, ads, polls and a repeated headline or byline are removed. Embedded posts become a link. Images get their real source (lazy attributes, then the widest `srcset`, then `src`, then a `<noscript>` copy) and furniture is dropped. HTML is reduced to an allowlist; links and images are made absolute.
7. **Fields.** Title, authors, dates, lead image, canonical URL, language and site name each follow a fixed source order, recorded in `fieldSources` and the trace.

### Confidence

```
body confidence = 1.0   visible body confirmed by a structured body
                  0.9   visible body meets the prose minimum
                  0.8   structured body used because the visible one is short
                  0.4 x (length / prose minimum)   body under the minimum
                  0     no body

confidence = body confidence
             x 0.6   if body_disagreement
             x 0.85  if visible_body_short
             x 0     if biography_only
             x 0     if not_an_article
             x 0.8   if the page had no type signal and was judged an article by its body alone
             clamped to 0..1
```

Every number lives in `ScoringWeights::DEFAULTS` and can be overridden with `withScoringWeights()`.

### Fetching

The fetcher exists so you do not have to write a careless one. Before every request, and again on every redirect hop, it checks the scheme and port, resolves the host and refuses private, loopback, link-local, unique-local, multicast, reserved and cloud metadata addresses, consults robots.txt (RFC 9309, no off switch), and takes a per-host rate-limit slot. `429` and `503` with `Retry-After` block the host and raise `RateLimitedException`; nothing is retried on its own. No cookies are stored or sent. `X-Robots-Tag` and robots meta tags are read: `noarchive`, `nosnippet`, `noai` and `none` remove the body, `noimageai` removes the lead image, `noindex` is reported.

## Fixtures and scoring

`fixtures/` holds synthetic pages modelled on the layouts of Indian news sites: articles in Hindi, Tamil, Bengali, Marathi, Malayalam, Telugu, Kannada, Gujarati, Punjabi, Urdu and English, liveblogs, video and gallery pages, section fronts, paywalls, script-only shells, broken charsets, lazy images and a page over 1 MB. Every page uses reserved example hosts and generic outlet labels, carries original placeholder text, and commits no images. `docs/fixture-coverage.json` lists them by script and layout.

`bin/readable-extract score` reports per-field accuracy, mean body F1 and the worst fixtures, and compares with `fixtures/baseline.json`. A drop larger than the tolerance, or a fixture that used to pass and now fails, exits 1. The baseline changes only through `baseline --write`.

Today all 56 fixtures pass with mean body F1 1.0. The fixtures were written with the extractor, so this is a regression guard, not an accuracy claim about real sites.

## Security and copyright

- The body HTML is allowlisted (no scripts, styles, event handlers, iframes, `javascript:`, `data:` or `vbscript:` URLs). Still run your own sanitiser (for example `wp_kses_post`) before rendering: a library allowlist is defence in depth, not a guarantee for every renderer.
- Extracted text belongs to its publisher. This library is not a licence to republish; how you use the text is your responsibility.
- The fetcher is for polite, occasional fetching under your own identity, not for bulk collection. It does not bypass paywalls, logins, consent walls or bot challenges, and it does not run JavaScript.

## Limitations

- libxml's parser predates HTML5. HTML5 elements parse and score, but its tree for some malformed markup differs from a browser's (see `docs/ARCHITECTURE.md`, decision 1).
- Pages that render their article in JavaScript are reported as `script_rendered`, not extracted.
- Month names are supported in English, Hindi and Marathi. Other scripts' visible dates are read only in ISO or numeric form.
- DNS is checked before each request, but curl resolves again when it connects. A host that changes its answer in that gap is not caught (decision 7).
- Measured on PHP 8.3 on a quiet 2-core box (load average 0.3): a 300 KB page extracts in about 33 ms and the 1.15 MB fixture in about 143 to 147 ms (median), inside the 150 ms target; about 50 ms of that is libxml parsing. On a loaded box expect two to three times that.

## FAQ

**Why is my body empty?** Look at `warnings`. `not_an_article`, `biography_only`, `needs_javascript`, `paywalled` and `robots_meta` each explain an empty body. Turn on `withTrace()` to see every candidate.

**Why a confidence and not just a body?** A wrong body is worse than an empty one: an empty body is noticed, a wrong one is trusted. Hold results under the bar for a human.

**Can it resolve aggregator wrapper links?** No. It recognises them (`wrapper`) and leaves them to a resolver such as `indianopinion/gnews-unwrap`.

**Does it cache pages?** No. Caching pages is the caller's job. The fetcher caches robots.txt and rate-limit slots in memory; implement `RobotsCache` and `RateLimitStore` over your own shared store to share them between workers.

**WordPress?** Call it from your import hook with the HTML you already have and pass your site's host to `withOwnHosts()`. See `examples/wordpress-import-hook.php`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to report issues, add fixtures and send changes.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See `LICENSE`.
