# readable-extract: architecture

This document describes how the library is built and records every
decision taken while building it from `docs/PRD.md`. Section 13 of the PRD
lists eight open questions; each has a ruling below, with the reason.

## Modules

| Path | Role |
|---|---|
| `src/Extractor.php` | Orchestrates one extraction: decode, parse, classify, body, fields, confidence |
| `src/ExtractorOptions.php`, `src/ScoringWeights.php` | Immutable options; every scoring and confidence number |
| `src/Article.php`, `Author.php`, `Image.php`, `Trace.php`, `PageContext.php` | Public value objects |
| `src/PageType.php`, `Warning.php`, `Source.php`, `AuthorKind.php`, `AuthorRole.php` | Enums with stable string values |
| `src/Internal/Charset.php` | Character set decision and conversion to UTF-8 |
| `src/Internal/HtmlParser.php` | libxml HTML parsing, tree normalisation (always html > head + body) |
| `src/Internal/Metadata.php` | JSON-LD, microdata, meta tags, link relations, lang, AMP, base href |
| `src/Internal/Body.php`, `BodyResult.php` | Working copy, scoring walk, candidate choice, in-body clean-up, liveblog body |
| `src/Internal/ImageRules.php`, `ImagePass.php` | Source choice, placeholder and furniture tests, the idempotent image pass |
| `src/Internal/Sanitiser.php` | Element and attribute allowlist, plain-text rendering |
| `src/Internal/TitleRules.php`, `Authors.php`, `Dates.php` | Title stripping, byline parsing, date parsing |
| `src/Internal/Url.php`, `Text.php`, `Dom.php`, `Scripts.php`, `WordCounter.php` | Helpers |
| `src/Fetch/*` | Opt-in fetcher: options, transport, DNS check, robots.txt, rate limits, robots directives |
| `src/Fixtures/*`, `src/Scoring/*` | Fixture loading and validation; scorer, report, baseline, comparison |
| `src/Cli/Application.php`, `bin/readable-extract` | Command line |
| `bootstrap.php` | Autoloader for use without Composer |

`Internal` classes are marked `@internal`: they may change in any release.

## Data flow

```
bytes + URL (+ PageContext)
  -> Url::assertInput           InvalidUrlException for bad URLs (the only throw)
  -> wrapper URL?               page type wrapper, stop
  -> Charset::toUtf8            BOM, header, meta charset, http-equiv, UTF-8; mismatch and invalid counts
  -> input cap, NFC
  -> HtmlParser::parse          DOMDocument, error count
  -> Metadata::read             JSON-LD nodes, chosen article node, meta, links, itemprops
  -> Body::workingCopy          clone; noscript images lifted; junk, hidden and paywalled parts removed
  -> title                      JSON-LD headline, og:title, h1, twitter:title, <title>; site name stripped
  -> page type, first pass      JSON-LD @type, og:type, URL path hints
  -> body                       liveblog updates, or scoring walk + structured candidates + choice + clean
  -> page type, second pass     index by link density, script_rendered, unknown to article, paywalled
  -> robots directives          body and lead image refused when the context says so
  -> authors, dates, lead image, canonical, host, language, script, sponsored signals
  -> confidence, field sources  -> Article
```

Fetching is a separate path: `HttpFetcher::fetch()` returns a
`FetchedPage`, and `toContext()` turns its headers and robots directives
into the `PageContext` that `extract()` reads.

## Decisions

### 1. HTML parsing on PHP 8.1 (ruling on Q1)

DOMDocument with libxml's HTML parser, flags `LIBXML_HTML_NOIMPLIED |
LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_COMPACT`. Errors go through
`libxml_use_internal_errors()` and are counted in the trace; the previous
setting is restored. The input is first converted to UTF-8 by `Charset`,
then prefixed with `<!DOCTYPE html><meta http-equiv="Content-Type"
content="text/html; charset=utf-8">`. The doctype matters: with
`NOIMPLIED`, a bare leading meta makes libxml drop everything after it.
The prefix meta wins over any charset the page declares, so libxml never
re-decodes. After parsing, the tree is normalised to `html > head + body`
and stray nodes are moved into the body, so fragments work too.

Why: no runtime dependency, available on every PHP 8.1 build with ext-dom,
and the PRD's tests run unchanged on 8.1 to 8.3.

HTML5 gaps of the libxml parser, and what the library does about each:

- HTML5 elements (`section`, `article`, `main`, `figure`, `time`) are
  unknown to libxml. They parse as generic elements and are scored like
  any other block; a parser error is counted for each.
- Implied end tags follow HTML4 rules. A `<p>` inside a `<p>` closes the
  first, as in browsers, but some other malformed nestings produce a tree
  that differs from a browser's. Scoring works on what libxml builds.
- `<template>` content is parsed as ordinary children, not a document
  fragment. Templates are removed before scoring.
- Non-ASCII attribute names are dropped. The AMP marker `<html ⚡>` is
  therefore detected from the source (first 8 KiB), not the DOM.
- Character references outside HTML4's set are decoded by libxml or, in
  JSON-LD strings, by `html_entity_decode` with `ENT_HTML5`.

PHP 8.4's `Dom\HTMLDocument` was not used. It returns a different class
hierarchy (`Dom\Element`, not `DOMElement`), so supporting it means a
second copy of every DOM walk, and the PRD's 8.1 floor would still need
the libxml path. A later release can add it behind the same interface.

### 2. Scoring weights and sibling join (ruling on Q2)

Every number is a constant in `ScoringWeights::DEFAULTS`, overridable
through `ExtractorOptions::withScoringWeights()`; unknown keys are refused.

Paragraph score (for `p`, `pre`, `td`, `blockquote`, and a `div` or
`section` that holds only text and inline elements), counted only when
the paragraph has at least 25 characters:

```
1 + 1.0 x commas (, U+060C U+3001 U+FF0C)
  + 0.5 x sentence marks (. ? ! danda U+0964, double danda U+0965, U+06D4, U+3002)
  + min(3, floor(length / 100))
```

The parent gets the full score, the grandparent half. A container starts
with its tag weight (`article` +10; `div`, `section`, `main` +5; `pre`,
`td`, `blockquote` +3; lists -3; headings -5), plus 25 per positive class
or id hint and minus 25 per negative one, plus 25 for
`itemprop="articleBody"`. The final score is multiplied by `1 - link
density`. The top container wins if its score is above zero.

Sibling join: a sibling of the top container joins the visible body when
its own score is at least `max(10, 0.2 x top score)`, or it is a `p` of
at least 80 characters with link density under 0.25, or a shorter `p`
with no links that ends in a sentence mark. Siblings with more negative
than positive hints never join.

Recorded result on the fixture suite: 56 of 56 fixtures pass, mean body F1
1.0 (`fixtures/baseline.json`). The fixtures are synthetic and written
alongside the extractor, so this is a guard against regressions, not a
measurement of accuracy on real sites.

### 3. Word splitting and body F1 tokens (ruling on Q3)

`WordCounter` decides per text. When at least half of the letters are in
a script that does not put spaces between words (Han, Hiragana,
Katakana, Thai, Lao, Khmer, Myanmar), it uses `IntlBreakIterator` word
boundaries if ext-intl is loaded (method `intl`), else counts each letter
of those scripts as one word (method `characters`). Every other text is
split on anything that is not a letter, mark, digit, ZWJ or ZWNJ (method
`whitespace`); Indic scripts use spaces, so they take this path. The
method is reported in `Article::$wordCountMethod`. Length tests always
use characters, never words.

The scorer's body F1 uses the same tokens, lowercased, counted as a
multiset.

### 4. File stores (ruling on Q4)

None. The library ships only in-memory `RobotsCache` and
`RateLimitStore`. Callers that need sharing across workers implement the
two small interfaces over their own store; `RateLimitStore::acquire()` is
specified as an atomic check-and-reserve. The library writes no files
except the CLI's `baseline --write` and `fixture --new`, to paths the user
names.

### 5. Month names (ruling on Q5)

A table of English (long and short), Hindi and Marathi month names,
including common spelling variants and both nukta forms of February.
Indic digits (Devanagari, Bengali, Gurmukhi, Gujarati, Oriya, Tamil,
Telugu, Kannada, Malayalam, Arabic-Indic) are converted to ASCII first, so
numeric dates work in every script. Other scripts' month names are not
read; such dates fall back to ISO or numeric forms only.

### 6. Confidence (ruling on Q6)

The PRD's formula: the body confidence multiplied down by the warnings
that matter, clamped to 0..1. Values (all in `ScoringWeights`):

| Input | Value |
|---|---|
| visible body confirmed by a structured body (word-pair overlap at or above the agreement bar) | 1.0 |
| visible body meets the prose minimum | 0.9 |
| structured body used | 0.8 |
| body under the minimum | 0.4 x length / minimum |
| `body_disagreement` | x 0.6 |
| `visible_body_short` | x 0.85 |
| `biography_only`, `not_an_article` | x 0 |
| no type signal, judged an article by its body alone | x 0.8 |

With the default readable bar of 0.5, a disagreement (0.54) is still
readable but a short body never is. The README carries the same table.

Per-field confidence for title, authors, published date and lead image
follows the source: JSON-LD 0.95, microdata 0.9, meta 0.85, visible 0.6
(0.7 for a single `h1` title), none 0. A published date read with an
assumed time zone is multiplied by 0.8.

### 7. DNS checks (ruling on Q7)

Before every request, including robots.txt and every redirect hop, the
fetcher resolves the host through `Resolver` (system DNS by default,
`dns_get_record` for A and AAAA, then `gethostbynamel`) and refuses the
request if any address is in a blocked range: `0/8`, `10/8`,
`100.64/10`, `127/8`, `169.254/16` (cloud metadata included), `172.16/12`,
`192.0.0/24`, `192.0.2/24`, `192.88.99/24`, `192.168/16`, `198.18/15`,
`198.51.100/24`, `203.0.113/24`, `224/4`, `240/4`, and for IPv6 `::/96`,
`::1`, `100::/64`, `2001::/23`, `2001:db8::/32`, `2002::/16`, `fc00::/7`,
`fe80::/10`, `fec0::/10`, `ff00::/8`; IPv4-mapped and NAT64 addresses are
checked by their embedded IPv4 address. The port must be in the allowed
list.

No pinning beyond that: the transport is not told which address to use.
The remaining gap is time of check to time of use: curl resolves the name
again when it connects, so a host whose DNS answer changes between the
check and the connection (DNS rebinding) is not caught. Callers that need
to close the gap can supply a `Transport` that connects to a fixed address
(for example with `CURLOPT_RESOLVE`) or run the fetcher behind an egress
proxy that enforces the same ranges. A custom transport gets the same
checks, since they run in `HttpFetcher`, not in the transport.

### 8. Fixture coverage map (ruling on Q8)

Each fixture's `meta.json` carries `script` (ISO 15924), `layout` (the
page type it represents) and `covers` (pattern tags). The test
`tests/Fixtures/FixtureSuiteTest.php` reads every `meta.json`, writes
`docs/fixture-coverage.json` (fixtures by script, by layout and by tag,
plus the list of required tags and any that are missing), and fails if a
required tag has no fixture or there are fewer than 40 fixtures. The file
is checked in, so a pull request that changes coverage shows it in the
diff.

### 9. Reserved hosts

Fixture and test URLs use only RFC 2606 and RFC 6761 names:
`example.com`, `example.net`, `example.org` and their subdomains, and the
`.example`, `.test`, `.invalid` and `.localhost` top-level domains. The
PRD mentions `example.in`; it is not a reserved name, so it is not used
and the fixture check refuses it. Vocabulary hosts that appear as
identifiers rather than sites (`schema.org`, `ogp.me`, `www.w3.org`,
`purl.org`) are allowed in page markup.

### 10. The wrapper page type has no fixture

A wrapper page is recognised from its URL alone, and fixture URLs must be
on reserved hosts, so no fixture can carry a real wrapper URL. Case 5 is
covered by unit tests (`InputTest`), and the coverage map records this
under `unitTestedOnly`.

### 11. Body source names

Case 23 speaks of source `structured_data`. The library reports the exact
structured source instead (`microdata` or `json_ld`) so a caller can tell
the two apart; the warning `visible_body_short` marks the fallback.

### 12. Body agreement

Agreement between a visible and a JSON-LD body is the share of distinct
word pairs (bigrams) they share, over the smaller set. Single words were
tried first; common function words made two unrelated English texts look
about 40% alike. With pairs, unrelated texts score near zero and the same
text scores near one.

### 13. Untyped pages

A page with no JSON-LD type and no usable `og:type` starts as `unknown`.
If its body meets the prose minimum it becomes `article`, with the
confidence multiplied by 0.8, because many small sites publish real
articles with no metadata. `og:type` `website` decides nothing on its
own: too many article pages carry it.

### 14. Biography detection

A visible candidate under the prose minimum is a biography when it is one
paragraph and either sits inside an element whose class or id mentions
author, profile, bio, byline, writer or columnist, or reads "Name is a /
writes on / covers ..." within its first 80 characters, with one to five
words before the phrase.

### 15. Canonical rules

A canonical candidate is ignored when it is not http(s), is the home page
of a non-home input, or belongs to another outlet. "Same outlet" means the
normalised hosts are equal or one is a subdomain of the other. Own hosts
are skipped with `own_host`, including the input URL, so `canonicalUrl`
and `host` can be null.

### 16. Robots.txt and rate-limit details

- Any 4xx for robots.txt, 429 included, means no restrictions (RFC 9309).
  5xx, timeouts and network failures mean full disallow and are not
  cached, so the next fetch tries again.
- The robots.txt request does not take a rate-limit slot.
- Within one `fetch()`, a redirect to a host that already took a slot in
  that call does not take another, so `http` to `https` on one host works
  with the default zero wait budget.
- `Retry-After` is capped at seven days.
- The user agent must contain a contact: `http://`, `https://`, `mailto:`
  or an `@`. The product token must be letters, `_` and `-` (RFC 9309).
- When an honoured directive refuses the body (`none`, `noarchive`,
  `nosnippet`, `noai`), the lead image is refused too; `noimageai` alone
  refuses only the lead image.

### 17. Paywalled parts

Elements whose class matches a JSON-LD `hasPart` `cssSelector` (simple
`.class` selectors) marked `isAccessibleForFree: false`, and elements
hidden by `hidden`, `aria-hidden="true"` or an inline `display:none` or
`visibility:hidden`, are removed before scoring. Their text is never read
into any field.

### 18. Liveblogs

Updates come from JSON-LD `liveBlogUpdate`, in the order the page gives
them. Each update's time is shown as given, then its headline and text;
updates are separated by `* * *`. `publishedAt` is `coverageStartTime` or
`datePublished`, else the earliest update; `modifiedAt` is the newest
update or `dateModified`, whichever is later. Without `liveBlogUpdate`
the page is scored like an article.

### 19. Script detection

`detectedScript` is reported whenever there is a body (ISO 15924 code of
the script with most letters and marks in the first 20,000 characters),
not only on a mismatch. `language_mismatch` is set when the declared
language is English and one Indic script holds more than half of the
letters. No language detection is done.

### 20. Performance

The PRD budget is 50 ms for a 300 KB page and 150 ms for 1 MB on a
typical server core. Measured on the shared 2-core build box under load
(load average 5 to 6): a realistic 300 KB page (scripts, styles, a large
menu, a related rail and a 1,400-character article) took 32 to 60 ms; the
1.2 MB fixture took 160 to 380 ms, about 70 ms of it in libxml parsing.
Junk and hidden elements are removed with XPath so libxml walks the tree,
and the hot paths use `textContent` rather than PHP tree walks. The 1 MB
figure needs confirming on a quiet core; the full suite scores in under
one second.
