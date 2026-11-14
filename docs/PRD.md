# readable-extract: product requirements

Status: draft for review. Package: `indianopinion/readable-extract`.
Namespace: `IndianOpinion\ReadableExtract`. Licence: MIT.

This document says what the library must do. It does not say how to write
it. The builder answers the open questions in `docs/ARCHITECTURE.md`.

Written from scratch; not derived from any third-party plugin.

Words used here:

- **Page**: one HTML document and the URL it was served from.
- **Article**: the result of extraction: body, title, authors, dates, lead
  image and the metadata around them.
- **Structured data**: JSON-LD blocks (`application/ld+json`), microdata
  (`itemprop`) and the Open Graph and article `<meta>` tags in the page head.
- **Visible body**: the article text as a reader sees it in the page markup,
  found by walking the DOM.
- **Prose minimum**: the least amount of body text, in characters after
  whitespace collapse, that counts as an article body. Default 400.
- **Furniture**: logos, icons, sprites, share buttons, spacers and tracking
  pixels. Never article content, never a lead image.
- **Fixture**: a saved HTML page plus the expected extraction for it, used
  to measure accuracy offline.
- **Fetcher**: the optional part of the library that downloads a page. It
  runs only when the caller calls it.

---

## 1. Problem

News importers usually take whatever text a feed or a generic scraper hands
them. On Indian news sites that text is often wrong in ways that are hard
to see:

1. **The wrong block wins.** A short author or desk biography sits in the
   markup before the article, and a naive extractor returns it as the body.
   The real text is in the page's structured data or further down.
2. **The lead image is a spacer.** Lazy-loading pages put a 1x1 placeholder
   in `src` and the real photo in `data-src` or `srcset`. A reader of plain
   `src` picks the tracker pixel, or the site logo, or a share button.
3. **Non-articles look like articles.** Video pages, playlists, photo
   galleries and section fronts carry a headline and some text. The text is
   whichever card the page happened to show.
4. **Dates and bylines drift.** Times are given in IST in visible text, in
   UTC in structured data, or not at all. Bylines mix people, desks and wire
   agencies.
5. **Nobody notices regressions.** Sites change templates without warning.
   Without saved pages and a score, an extractor gets worse silently.

Measured in production (one line each):

- One outlet's importer feed stored an author biography in place of the
  article for about 25 items in four days; one of them went on to carry
  figures that were not in the source story.
- Storing raw importer HTML without normalising lazy images put tiny
  thumbnails on 6.8% of published posts.

A wrong body is worse than an empty one. An empty body is noticed and
retried. A wrong body is trusted and repeated.

## 2. Goals and non-goals

### Goals

1. Extract, from one page, the body (clean HTML and plain text), title,
   authors, published and modified dates, lead image, canonical URL,
   language, site name and page type.
2. Work offline on HTML the caller already has. Extraction never touches
   the network.
3. Fetch a page only when the caller explicitly asks, and then honour
   robots.txt, robots meta and header directives, crawl delays, `429` and
   `Retry-After`, with a per-host rate limiter on by default.
4. Say where every field came from (structured data, meta tag, visible
   markup) and how confident the extractor is, so a caller can hold weak
   results for a human.
5. Recognise pages that are not articles and say so, instead of returning
   their text as a body.
6. Treat Indic scripts as first-class: UTF-8 throughout, Unicode
   normalisation, character-based length tests that work for Devanagari,
   Tamil, Bengali and the other scripts Indian outlets publish in.
7. Ship a fixture suite modelled on the page patterns of Indian news sites,
   and a scorer that reports per-field accuracy against a committed
   baseline, so a regression fails CI.
8. Zero runtime Composer dependencies. PHP 8.1+. Usable with or without
   Composer.

### Non-goals

- Bypassing paywalls, logins, consent walls, CAPTCHAs, bot challenges or
  rate limits. No proxy rotation, no headless browser, no cookie forging,
  no browser user-agent impersonation.
- Executing JavaScript. Pages that render their article only in script are
  reported as such.
- Crawling. The library never follows links to other articles, next pages,
  "load more" endpoints or comment threads.
- Downloading images. The lead image is a URL; the caller decides whether
  to fetch it.
- Resolving news aggregator wrapper links. That belongs to a separate
  library (`indianopinion/gnews-unwrap`); this one detects them and refuses.
- Deciding whether an article is sponsored, relevant, true or fit to
  publish. The library reports signals; the caller decides.
- Retail product pages, search result pages or any scraping of listings.
- Translation, summarisation or any language model call.
- A port of any existing extraction library. The scoring heuristic is our own and documented.

## 3. Users and setting

- **Who installs it:** PHP developers who handle news links: aggregators,
  newsroom tools, WordPress importers, archivists, researchers.
- **Where it runs:** in a cron job, queue worker or import hook, or a CLI.
  Never in the request path of a page view if it fetches.
- **Typical use:** the caller already holds the HTML (from its own importer
  or a cache) and calls the extractor. Fetching is the minority path.
- **Typical volume:** tens to a few thousand pages an hour, spread over
  many hosts. The default rate limiter keeps any one host slow.
- **Environment:** PHP 8.1, 8.2 or 8.3 with `ext-dom`, `ext-libxml` and
  `ext-mbstring`. `ext-curl` is needed only for the default HTTP
  transport. `ext-intl` is optional and, when present, used for Unicode
  normalisation and IDN hosts.

## 4. Behaviour

Every numbered item below is a case. Section 12 has one test per case.

### 4.1 Input

1. `extract(html, url)` takes the page HTML as a string and the URL it came
   from. The URL is required: it resolves relative links and is the
   fallback canonical URL.
2. Empty or whitespace-only HTML gives an Article with page type `empty`,
   no body, confidence 0 and warning `empty_input`. No exception.
3. HTML larger than the input cap (default 5 MB) is cut at the cap on a
   character boundary and parsed; warning `input_truncated`.
4. A URL that is not absolute `http` or `https` raises
   `InvalidUrlException`. So does a URL over 8 KB, or one with userinfo.
5. A URL that is a news aggregator wrapper link (a known wrapper host and
   path shape, the same set gnews-unwrap documents) gives page type
   `wrapper` and warning `wrapper_url_not_resolved`. Nothing is extracted
   from the interstitial.
6. Encoding: the extractor decides the character set in this order: a
   byte-order mark, the caller's declared charset (from a `Content-Type`
   header passed in), `<meta charset>`, `<meta http-equiv="Content-Type">`,
   then UTF-8. Bytes are converted to UTF-8 before parsing. Invalid
   sequences are replaced, never dropped silently; warning
   `invalid_encoding` records how many.
7. A page declared as ISO-8859-1 or windows-1252 that is in fact valid
   UTF-8 (a common template fault) is read as UTF-8; warning
   `charset_mismatch`.
8. All output text is Unicode NFC when `ext-intl` is present. Without it,
   text passes through and warning `no_normalizer` is recorded once per
   extractor.
9. HTML entities, numeric references and non-breaking spaces are decoded in
   all text fields. Zero-width joiners and non-joiners are kept (they are
   meaningful in Indic scripts); other zero-width and control characters
   are removed.

### 4.2 Parsing

10. The page is parsed with a real HTML parser into a DOM. No field is
    extracted by running a regular expression over the whole document.
    (Regex over a large body can hit the backtrack limit and return
    nothing; the extractor must never lose content that way.)
11. Malformed HTML (unclosed tags, stray `</div>`, HTML5 elements unknown
    to the parser, nested `<p>`) still parses. Parser warnings are
    suppressed through the parser's own error handling, never with `@`,
    and counted in the trace.
12. The parser never loads external resources or entities. A document type
    declaration that names a remote DTD is ignored.
13. Before body scoring, these are removed from a working copy: `script`,
    `style`, `noscript` (after reading any image inside it, see 31),
    `template`, `iframe`, `form`, `button`, `svg`, `canvas`, `nav`,
    `footer`, `aside`, and elements hidden with the `hidden` attribute or
    `aria-hidden="true"`. JSON-LD scripts are read before removal.

### 4.3 Page type

14. The extractor assigns one page type: `article`, `liveblog`, `video`,
    `gallery`, `index`, `paywalled`, `script_rendered`, `wrapper`,
    `empty` or `unknown`.
15. Structured data `@type` decides first: `NewsArticle`, `Article`,
    `ReportageNewsArticle`, `AnalysisNewsArticle`, `OpinionNewsArticle`,
    `BlogPosting` and similar give `article`; `LiveBlogPosting` gives
    `liveblog`; `VideoObject` as the main entity gives `video`;
    `ImageGallery` or `MediaGallery` gives `gallery`; `CollectionPage`,
    `ItemList` or `WebPage` with a list as main entity gives `index`.
    `og:type` is the fallback (`article` versus `video.*` and `website`).
16. URL path hints refine an `unknown` or `article` guess: a path segment
    such as `/video/`, `/videos/`, `/photos/`, `/photo-gallery/`,
    `/gallery/`, `/web-stories/`, `/tag/`, `/topic/`, `/author/` or
    `/section/` with no `article` structured data gives the matching type.
    The hint list is configurable.
17. A page whose visible body falls under the prose minimum while it holds
    many short headline links (link density above the threshold, default
    0.5) is `index`.
18. For `video`, `gallery` and `index` the body is left empty, with warning
    `not_an_article`. Title, image and URL are still returned. The text
    such a page shows is whichever card was on screen and must not be
    passed off as a body.
19. `liveblog` bodies are the updates in page order, each with its own
    timestamp when present, joined with a separator. `publishedAt` is the
    first update's time if the page gives no start time; `modifiedAt` is
    the newest update.
20. A page whose `<body>` holds almost no text but a script bundle, with no
    structured body, is `script_rendered`; warning `needs_javascript`.

### 4.4 Body

21. Candidates for the body are gathered from all of these, and each is
    measured in characters of plain text after whitespace collapse:
    a. the visible body found by the scoring walk (22);
    b. an element marked `itemprop="articleBody"`;
    c. the `articleBody` string in an article-typed JSON-LD block.
22. The scoring walk rates block containers by paragraph count, text
    length, comma and sentence-mark count (including the danda `।` and
    double danda `॥`), and link density, and by class and id hints
    (positive: `article`, `story`, `content`, `body`, `entry`, `post`;
    negative: `comment`, `related`, `share`, `social`, `newsletter`,
    `promo`, `sponsor`, `advert`, `widget`, `sidebar`, `breadcrumb`,
    `author-bio`, `profile`, `trending`, `also-read`, `read-more`).
    Hint lists are configurable. The top-scoring container plus qualifying
    siblings is the visible body.
23. Choice: the visible body wins when it meets the prose minimum. If it is
    under the minimum and a structured body (b or c) meets it, the
    structured body wins, with source `structured_data` and warning
    `visible_body_short`.
24. A short block that reads as a biography (one paragraph, under the prose
    minimum, inside an author or profile container, or matching a
    configurable biography pattern such as a byline name followed by
    "is a", "writes on", "covers") never wins over a longer structured
    body. When it is the only candidate, the body is empty with warning
    `biography_only`, not the biography.
25. When the visible body and the JSON-LD body both meet the minimum but
    share little text (token overlap under 0.3), the visible body wins, the
    confidence for the body drops, and warning `body_disagreement` is set.
    Neither text is merged into the other.
26. Inside the chosen body, these are removed: inline "also read" and
    "read more" link blocks, related-story lists, newsletter and app
    prompts, share bars, ad slots, embedded polls, and repeated copies of
    the headline or byline at the top. Embedded tweets and posts are kept
    as a link, not their script.
27. Body HTML is reduced to an allowlist: `p`, `h2` to `h6`, `ul`, `ol`,
    `li`, `blockquote`, `figure`, `figcaption`, `img`, `a`, `strong`, `em`,
    `b`, `i`, `br`, `table`, `thead`, `tbody`, `tr`, `th`, `td`, `pre`,
    `code`. Attributes are reduced to `href` (absolute `http` or `https`
    only), `src`, `alt`, `width`, `height` on `img`, and `colspan`,
    `rowspan` on cells. All `on*` attributes and `javascript:`, `data:` and
    `vbscript:` URLs are dropped.
28. Relative links and image sources in the body are made absolute against
    `<base href>` when present, else the page URL.
29. Body text is the plain-text rendering of body HTML: paragraphs
    separated by one blank line, list items on their own lines, no
    furniture text.
30. `wordCount` counts words for scripts with spaces and is reported with
    `charCount`; length tests always use characters, so a Tamil or
    Malayalam article is not judged short by word count.

### 4.5 Images

31. For every `<img>` in the body and every lead image candidate, the
    best real source is chosen in this order: lazy attributes
    (`data-src`, `data-lazy-src`, `data-original`, `data-hi-res-src`,
    `data-image`, `data-url`), then the widest candidate of `data-srcset`
    or `srcset`, then `src`, then an `<img>` inside a sibling `<noscript>`.
    A density descriptor (`2x`) ranks above any width descriptor. Each
    candidate is skipped if it fails 32 to 35. The attribute list is
    configurable.
32. A URL is a placeholder when it is empty, a `data:` URI, not `http` or
    `https`, or its file name without extension matches, as a whole, a
    placeholder word: `spacer`, `blank`, `pixel`, `placeholder`,
    `transparent`, `empty`, `dummy`, `noimage`, `no-image`, `lazy`,
    `lazyload`, `loading`, `grey`, `gray`, `dot`, `px`, or a tiny size like
    `1x1`, optionally with a short number suffix. Matching is on the whole
    name, never a substring: `phone-pixel-10.jpg` is a photo.
33. An `<img>` whose own `width` or `height` attribute is under the
    furniture bar (default 200 wide or 120 high) is furniture; no source in
    that tag is used.
34. A URL that advertises its size in an explicit, labelled form is
    furniture when under the bar: `?size=WxH` or `?size=W:H`, `w=`,
    `width=`, `h=`, `height=` query values, and path forms like
    `width-N`, `height-N`, `SQUARE_N` or a `WxH` segment. A bare number in
    a path is never read as a size.
35. A URL whose path ends `.svg` or `.ico`, or has a whole path segment or
    name part such as `logo`, `icon`, `avatar`, `sprite`, `emoji`,
    `badge`, `button`, `btn`, `arrow`, `watermark` or `lightbox`, is
    furniture.
36. Images that fail 31 to 35 are removed from the body HTML; images that
    pass have their `src` set to the chosen URL. Running the image pass
    twice gives the same bytes as running it once.
37. Lead image order: JSON-LD `image` on the article (largest when a list),
    `og:image` (and `og:image:secure_url`), `twitter:image`, `<link
    rel="image_src">`, then the first body image that passes. Each
    candidate passes the same tests as 32 to 35; a spacer in `og:image` is
    skipped, not returned. Declared width and height are reported when the
    source gives them.
38. No candidate passes: `leadImage` is null with warning `no_lead_image`.
    The extractor never invents an image and never fetches one to measure
    it.

### 4.6 Title

39. Title order: JSON-LD `headline`, `og:title`, the single `<h1>` inside
    or nearest the body, `twitter:title`, `<title>`.
40. Site-name suffixes and prefixes are stripped (`Headline | Site`,
    `Headline - Site News`, `Site: Headline`) when the removed part matches
    the site name or the host, and never when stripping would leave fewer
    than three words or 15 characters.
41. Titles are trimmed, whitespace collapsed, entities decoded. A title in
    all capitals is kept as given.

### 4.7 Authors

42. Author order: JSON-LD `author` (a Person or a list, `name` field),
    `<meta name="author">`, `article:author` when it is a name rather than
    a URL, `rel="author"` links, then a byline element by class hint.
43. Each author is an entry with `name`, optional `url`, and `kind`:
    `person`, `organisation` or `agency`. JSON-LD `Organization` is
    `organisation`. Names that match a configurable list of wire agency and
    desk credits (the default list holds generic forms such as "News
    Service", "Desk", "Bureau", "Agencies", "Web Desk", "Online Desk")
    are `agency`. The library ships no outlet-specific list.
44. Byline text is split on commas, "and", "&" and their common Hindi form
    "और", with "By", "Written by", "Edited by", "Reported by" and their
    Hindi equivalents stripped. "Edited by" credits are reported with
    `role` `editor`, not as authors.
45. Duplicate names (case and whitespace insensitive) are merged. A name
    that is a URL, an email address or longer than 80 characters is
    dropped with warning `bad_author`.
46. No author found: an empty list. The site name is never used as a
    stand-in author.

### 4.8 Dates

47. Published date order: JSON-LD `datePublished`,
    `article:published_time`, `<meta itemprop="datePublished">`,
    `<time datetime>` inside the article header, then visible date text.
    Modified date uses the matching modified fields.
48. Machine dates are parsed as ISO 8601. A value with no offset is read
    in the configured default zone (default `Asia/Kolkata`) and the
    warning `assumed_timezone` is set. All dates are returned as
    `DateTimeImmutable` in UTC, with the original string kept in the trace.
49. Visible date text is parsed for the common forms on Indian sites:
    "14 Nov 2026, 10:30 IST", "Nov 14, 2026 10:30 am IST",
    "Updated: 14/11/2026", "Published On: 14-11-2026 10:30". `IST` means
    UTC+05:30. Day-first is assumed for numeric dates. English month
    names, short and long, are supported; Hindi month names are supported;
    other scripts are an open question (Q5).
50. Relative text ("2 hours ago", "Yesterday") is parsed only when the
    caller passes a reference time; otherwise the date is null with
    warning `relative_date`.
51. A published date in the future beyond the tolerance (default 1 day), or
    before 1990, is rejected with warning `implausible_date`. A modified
    date earlier than the published date is dropped with warning
    `modified_before_published`.
52. No date found: null. The extractor never fills in today's date. A
    section front or undated page often carries old content, and a fake
    "now" makes it look fresh.

### 4.9 URL, language, site and access

53. Canonical URL order: `<link rel="canonical">`, `og:url`, JSON-LD `url`
    or `mainEntityOfPage`, then the input URL. A canonical that points to
    another host's outlet, to the site's home page, or to a non-`http`
    scheme is ignored with warning `canonical_ignored`.
54. If the input page is an AMP page (`<html amp>` or `<html ⚡>`), the
    canonical non-AMP URL is reported as canonical and `ampUrl` is set to
    the input. An AMP URL is never preferred over the canonical.
55. The canonical URL has tracking parameters removed (`utm_*`, `fbclid`,
    `gclid`, and a configurable list) and its fragment dropped. Path and
    other parameters are kept. The extractor does not try to compute a
    dedup key beyond this; callers that need one use a dedicated library.
56. The caller may declare its own hosts. A canonical, `og:url` or input
    URL on one of those hosts is not reported as the article's source
    (warning `own_host`), because crediting a story to the importing site
    corrupts attribution.
57. `host` is reported lowercased with mirror labels (`www`, `amp`, `m`,
    `mobile`) stripped from the left. The caller may pass a host normaliser
    callable to replace this rule; the library needs no other package for
    it.
58. Language order: JSON-LD `inLanguage`, `<html lang>`, `og:locale`,
    `Content-Language`. Values are reported as BCP 47 tags (`hi-IN`,
    `ta`). When the declared language is English but most of the body's
    letters are in one Indic script, the script is reported in
    `detectedScript` and warning `language_mismatch` is set. No other
    language detection is done.
59. Site name order: `og:site_name`, JSON-LD `publisher.name`, then the
    host.
60. Access: JSON-LD `isAccessibleForFree: false` sets `accessibleForFree`
    false. When the body is also under the prose minimum the page type is
    `paywalled` with warning `paywalled`. The extractor never reads hidden
    paywalled text that the page ships but hides, and never returns it.
61. Sponsored signals are reported, not acted on: JSON-LD or meta markers
    and visible labels such as "Sponsored", "Partner content",
    "Advertorial", "Brand studio", "Press release". `sponsoredSignals` is a
    list of what was seen.

### 4.10 Confidence and trace

62. Each of body, title, authors, published date and lead image carries a
    source (`json_ld`, `microdata`, `meta`, `visible`, `none`) and a
    confidence from 0 to 1. The overall confidence is the body confidence
    multiplied down by warnings that matter (`body_disagreement`,
    `visible_body_short`, `biography_only`, `not_an_article`). The formula is
    documented in ARCHITECTURE.md and covered by tests.
63. `isReadable()` is true when the page type is `article` or `liveblog`,
    the body meets the prose minimum and overall confidence is at or above
    the readable bar (default 0.5). Callers are told in the README to hold
    anything else for a human.
64. With tracing on, the Article carries a trace: every candidate per
    field, its source, its score and why it lost. Tracing is off by
    default.

### 4.11 Fetching (only when the caller asks)

65. Extraction never fetches. The only network path is a `Fetcher` the
    caller constructs and calls, or the convenience `fromUrl()` that the
    caller calls by name. No class fetches on construction, on autoload,
    or as a side effect of extraction.
66. The caller must set a user agent that names its product and a contact
    URL or address. A missing or empty user agent raises
    `ConfigurationException`. A user agent that impersonates a browser
    (starts `Mozilla/`) is refused the same way. The library never rotates
    user agents.
67. robots.txt is fetched for each scheme, host and port before the first
    page request and parsed per RFC 9309: the group for the configured
    product token, else `*`; longest match wins; `Allow` wins a tie; `*`
    and `$` patterns are supported. A disallowed path raises
    `RobotsDisallowedException`. This check cannot be switched off.
68. robots.txt status handling per RFC 9309: `2xx` is parsed; `4xx` means
    no restrictions; `5xx`, timeout or network failure means full
    disallow until the next attempt. Redirects are followed up to 5 hops.
    Bodies over 500 KiB are cut there. Results are cached per host for 24
    hours by default, in a pluggable cache.
69. `Crawl-delay` in the matching group sets the per-host interval when it
    is longer than the configured one, capped at the configured maximum
    (default 60 s). A longer value makes the fetcher refuse with
    `RateLimitedException` rather than wait.
70. A per-host rate limiter is on by default: at most one request per host
    per interval (default 10 s). When the next slot is later than the
    caller's wait budget (default 0, do not wait), the fetcher raises
    `RateLimitedException` with the time the slot opens. The limiter store
    is pluggable (in-memory by default) so separate workers can share it.
71. `429` or `503` with `Retry-After` (seconds or HTTP date) blocks that
    host until then, recorded in the limiter store, and raises
    `RateLimitedException`. Without `Retry-After` the block is 10 minutes
    by default. The fetcher never retries on its own.
72. The `X-Robots-Tag` header and `<meta name="robots">` (plus a
    product-specific meta name) are read on every fetched page. `noindex`
    is reported only. `none`, `noarchive`, `nosnippet`, `noai` and
    `noimageai` are honoured by default: the fetcher returns the page with
    `robotsRefused` set and the extractor returns no body (and, for
    `noimageai`, no lead image), warning `robots_meta`. The caller can make
    this stricter, not looser, for `none` and `noarchive`.
73. Redirects are followed manually, at most 5 hops. Each hop is checked
    against robots.txt for its host and against the network rules in 74.
    A loop raises `FetchException` with reason `redirect_loop`.
74. Only `http` and `https` on ports 80, 443 or a configured list are
    fetched. A host that resolves to a private, loopback, link-local,
    unique-local, multicast or cloud metadata address is refused
    (`BlockedAddressException`), checked after DNS resolution and pinned
    for the request so a rebinding answer cannot slip through. Tests can
    allow loopback explicitly.
75. Limits: connect timeout 5 s, total 20 s, body cap 5 MB (the read stops
    there, warning `body_truncated`). A content type other than
    `text/html` or `application/xhtml+xml` raises `FetchException` with
    reason `unexpected_content_type`.
76. `4xx` other than `429` raises `HttpStatusException` with the status.
    `5xx` other than `503` with `Retry-After` does the same. Network,
    DNS and TLS failures raise `NetworkException`. Nothing is swallowed.
77. Cookies are not stored or sent. No `Authorization` header is sent
    unless the caller passes one for its own licensed source.
78. The fetched result carries final URL, status, headers, charset, body,
    robots directives and the hop list, and can be passed straight to
    `extract()`.

### 4.12 Fixtures and scoring

79. A fixture is a directory holding `page.html`, `expected.json` and
    `meta.json`. `expected.json` lists the expected fields: `pageType`,
    `title`, `authors`, `publishedAt`, `modifiedAt`, `leadImage`,
    `canonicalUrl`, `language`, and `bodyText` (or `bodyMustContain` and
    `bodyMustNotContain` lists). A field left out is not scored.
80. `meta.json` records the URL the page stands for, the kind
    (`synthetic` or `captured`), the outlet label, the pattern it
    exercises, the date written or captured, and for captured pages the
    terms page consulted, the date it was read and a one-line note of what
    the terms allow.
81. The suite is for extraction accuracy on saved pages. It is not a list
    of sites to fetch, and nothing in the repo fetches its URLs. The
    scorer and the tests run offline only.
82. Synthetic fixtures are the default. The builder writes them by hand,
    reproducing structural patterns seen on Indian news sites (the
    patterns in 4.3 to 4.9), with original placeholder text. They may use a
    descriptive outlet label but use `example.in`, `example.com` and
    similar reserved hosts.
83. A captured fixture is allowed only where the outlet's published terms
    permit keeping a copy for this purpose. Article prose in a captured
    page is replaced with original or public-domain text of similar length
    and script before commit; markup, attributes and structure are kept.
    Images are never committed; image URLs stay as text.
84. The suite covers at least: each page type in 14; each body case in 21
    to 26; each image case in 31 to 38; each date form in 47 to 52;
    bylines with agencies and editors; AMP and canonical; pages in Hindi,
    Tamil, Bengali, Marathi, Malayalam, Telugu, Kannada, Gujarati, Punjabi
    and Urdu (right-to-left); a broken charset page; and a page over 1 MB.
    At least 40 fixtures at release.
85. The scorer runs the extractor over every fixture and computes per
    fixture and per field: exact match for page type, language and
    canonical URL; normalised match (case, whitespace, punctuation) for
    title; set precision and recall for authors; match within a tolerance
    (default 60 s) for dates; normalised URL match for lead image; and
    token precision, recall and F1 for body text, with must-contain and
    must-not-contain checks.
86. The report gives per-field accuracy, mean body F1, the worst ten
    fixtures, and a diff against a baseline file. Formats: plain text for a
    terminal, JSON for machines, Markdown for a pull request comment.
87. A baseline file (`fixtures/baseline.json`) is committed. The scorer
    exits non-zero when any field's accuracy or the mean body F1 drops by
    more than the tolerance (default 0.01 absolute), or when a fixture that
    passed now fails. Improvements are reported and the baseline is
    updated only by an explicit command.
88. A fixture whose files are missing or whose JSON is invalid fails the
    run with the fixture name; it is not skipped.

## 5. Public interface

Names below are the public API. The builder may add internals freely.

### 5.1 Extraction

- `Extractor` (final). `__construct(?ExtractorOptions $options = null)`.
  - `extract(string $html, string $url, ?PageContext $context = null): Article`
- `PageContext` (readonly properties): `?string $contentType`,
  `array $headers`, `?DateTimeImmutable $referenceTime`,
  `?RobotsDirectives $robots`. Built by the caller or by `FetchedPage`.
- `Article` (readonly value object, `JsonSerializable`):
  `pageType` (enum `PageType`), `title`, `bodyHtml`, `bodyText`,
  `wordCount`, `charCount`, `authors` (list of `Author`), `editors`,
  `publishedAt`, `modifiedAt`, `leadImage` (`?Image`), `canonicalUrl`,
  `ampUrl`, `host`, `language`, `detectedScript`, `siteName`,
  `accessibleForFree` (`?bool`), `sponsoredSignals`, `warnings` (list of
  `Warning` enum values), `fieldSources`, `fieldConfidence`,
  `confidence`, `trace` (`?Trace`). Methods: `isReadable(): bool`,
  `toArray(): array`.
- `Author`: `name`, `?url`, `kind` (`person`, `organisation`, `agency`),
  `role` (`author`, `editor`).
- `Image`: `url`, `?width`, `?height`, `source`.
- `ExtractorOptions` (immutable, `with*()` methods): see section 7.

### 5.2 Fetching

- `Fetcher` interface: `fetch(string $url): FetchedPage`.
- `HttpFetcher implements Fetcher`. `__construct(FetchOptions $options,
  ?Transport $transport = null, ?RobotsCache $robotsCache = null,
  ?RateLimitStore $limits = null, ?callable $logger = null)`.
- `Transport` interface with `CurlTransport` as the default. Tests use a
  scripted fake transport.
- `RobotsCache` and `RateLimitStore` interfaces, each with an in-memory
  implementation. A file-based store is optional (Q4).
- `FetchedPage`: `finalUrl`, `status`, `headers`, `charset`, `body`,
  `hops`, `robots` (`RobotsDirectives`), `robotsRefused`,
  `toContext(): PageContext`.
- `ReadableExtract::fromUrl(string $url, FetchOptions $fetch,
  ?ExtractorOptions $extract = null): Article`: fetch then extract, one
  explicit call.

### 5.3 Exceptions

All extend `ReadableExtractException`: `InvalidUrlException`,
`ConfigurationException`, `FetchException` (with a `reason` code),
`RobotsDisallowedException`, `RateLimitedException` (with
`retryAt`), `BlockedAddressException`, `HttpStatusException` (with
`status`), `NetworkException`, `FixtureException`. Extraction of odd HTML
never throws; it returns warnings.

### 5.4 Scoring

- `Fixtures\Suite::load(string $dir): Suite`
- `Scoring\Scorer::__construct(Extractor $extractor, ScoreOptions $o)`;
  `score(Suite $suite): Report`
- `Report`: `toText()`, `toJson()`, `toMarkdown()`,
  `compare(Baseline $b): Comparison`; `Comparison::isRegression(): bool`.

### 5.5 CLI (`bin/readable-extract`)

- `extract <file.html> --url=<url> [--json] [--trace]`: offline.
- `extract --fetch <url> --user-agent=<ua> [--json]`: fetches, only with
  `--fetch` given explicitly.
- `score [<fixtures-dir>] [--format=text|json|markdown]
  [--baseline=<file>] [--tolerance=0.01]`: exit 0 pass, 1 regression,
  2 usage or fixture error.
- `baseline --write [<fixtures-dir>]`: rewrites the baseline.
- `fixture --new <name>`: writes an empty fixture skeleton with a
  `meta.json` template that asks for the terms fields.

### 5.6 Without Composer

`require 'path/to/readable-extract/bootstrap.php';` registers an autoloader
for the namespace. Nothing else is needed.

## 6. Data

- The library stores nothing by default. In-memory robots and rate-limit
  stores live for the process.
- Pluggable stores (robots.txt cache, rate-limit slots) hold host names,
  robots rules, slot times and block-until times only. No page content,
  no personal data.
- Fixtures live in the repo under `fixtures/`, with `baseline.json`.
  They are excluded from the Composer dist archive via `.gitattributes`
  so installs stay small.
- No retention or uninstall step: removing the package removes
  everything.

## 7. Configuration and sensible defaults

| Option | Default | Notes |
|---|---|---|
| `proseMinimum` | 400 chars | Body below this is "short" |
| `readableBar` | 0.5 | `isReadable()` threshold |
| `inputCap` | 5 MB | HTML beyond is cut |
| `linkDensityIndex` | 0.5 | Above this with short body: `index` |
| `bodyAgreement` | 0.3 | Token overlap below this: disagreement |
| `furnitureMinWidth` / `furnitureMinHeight` | 200 / 120 px | Image furniture bar |
| `lazyAttributes` | list in case 31 | Ordered |
| `placeholderNames`, `chromeWords` | lists in 32, 35 | Extendable |
| `positiveHints`, `negativeHints` | lists in 22 | Extendable |
| `nonArticlePathHints` | list in 16 | Extendable |
| `agencyPatterns` | generic list in 43 | Extendable; ships no outlet list |
| `defaultTimezone` | `Asia/Kolkata` | For offset-less dates |
| `futureTolerance` | 1 day | Implausible date bar |
| `trackingParams` | `utm_*`, `fbclid`, `gclid`, more | Stripped from canonical |
| `ownHosts` | empty | Hosts never reported as source |
| `hostNormaliser` | built-in mirror strip | Callable `string -> string` |
| `trace` | false | Per-field candidate trace |
| `userAgent` (fetch) | none, required | Must name product and contact |
| `robotsProductToken` (fetch) | first token of UA | For robots groups |
| `hostInterval` (fetch) | 10 s | Per-host spacing |
| `maxCrawlDelay` (fetch) | 60 s | Above this, refuse |
| `waitBudget` (fetch) | 0 s | How long `fetch()` may sleep for a slot |
| `defaultBackoff` (fetch) | 600 s | After 429 or 503 without `Retry-After` |
| `robotsTtl` (fetch) | 24 h | robots.txt cache |
| `connectTimeout` / `totalTimeout` | 5 s / 20 s | |
| `bodyCap` (fetch) | 5 MB | |
| `maxRedirects` | 5 | 0 to 10 |
| `allowedPorts` | 80, 443 | |
| `allowPrivateAddresses` | false | Tests only |
| `honourRobotsMeta` | `none`, `noarchive`, `nosnippet`, `noai`, `noimageai` | Can add, not remove `none`, `noarchive` |

## 8. Security and privacy

1. Untrusted HTML in, sanitised HTML out (27). The README still tells
   callers to run their own output sanitiser (for example `wp_kses_post`)
   before rendering, because a library allowlist is defence in depth, not
   a guarantee for every renderer.
2. The parser loads no external entities, DTDs or resources (12).
3. Fetch refuses private and metadata addresses after resolution, pins the
   resolved address, and checks every redirect hop (73, 74).
4. No `eval`, no shell, no `@`, no dynamic includes. No file is written
   except by the CLI `baseline --write` and `fixture --new` commands, to
   paths the user names.
5. No cookies, no credentials stored, no browser impersonation (66, 77).
6. The library honours robots.txt without an off switch, robots meta and
   header directives, crawl delays and `Retry-After`. It is built for
   polite, occasional fetching under the caller's own identity, not for
   bulk collection.
7. Logs (via the optional logger callable) carry URLs, hosts, status codes
   and reason codes. Never page bodies, never headers that may hold
   cookies.
8. Copyright: extracted text belongs to its publisher. The README says the
   caller is responsible for how it uses extracted text and that the
   library is not a licence to republish.
9. Captured fixtures follow 83: terms checked and recorded, prose replaced,
   no images committed.

## 9. Performance budget

- `extract()` on a 300 KB page: under 50 ms on PHP 8.3 on a typical
  server core; on a 1 MB page, under 150 ms. Peak memory under 64 MB for a
  5 MB input.
- No quadratic passes over the DOM: each scoring pass is linear in node
  count.
- The full fixture suite scores in under 10 s in CI.
- Fetch cost is dominated by the network and the rate limiter, by design.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. No 8.2+ syntax. `ext-dom`, `ext-libxml`,
  `ext-mbstring` required; `ext-curl` for the default transport;
  `ext-intl` optional (8, 57).
- libxml's HTML parser is HTML4-era; HTML5 elements must still parse and
  be scored (11). See Q1.
- Works inside WordPress (a README note shows calling it from an import
  hook and passing `home_url()` hosts as `ownHosts`), Laravel, Symfony or
  plain scripts. No global state.
- Linux, macOS and Windows for extraction. Fetch tested on Linux.

## 11. Observability

- `Article::warnings` and `fieldSources` tell an operator why a result
  looks the way it does without a debugger.
- `trace` (opt-in) lists every candidate per field and why it lost.
- The optional logger callable receives `(level, message, context)` for
  fetch events: robots decision, rate-limit block, redirect hop, status,
  bytes read, refusal reason.
- The CLI `extract --trace` prints the trace as text.
- The scoring report is the long-term health signal; CI posts the
  Markdown form on pull requests that change extraction code.

## 12. Acceptance tests

Each test names the case it proves. All tests run offline. Fetch tests use
a scripted transport and a stub DNS resolver.

1. (1) Relative `href` in body resolves against the given URL.
2. (2) `''` and `"  \n"` give page type `empty`, warning `empty_input`.
3. (3) A 6 MB page is cut to 5 MB, parses, warning `input_truncated`.
4. (4) `ftp://x`, `/a`, a 9 KB URL and `https://u:p@x.in/a` each raise
   `InvalidUrlException`.
5. (5) A wrapper-shaped aggregator URL gives page type `wrapper`.
6. (6) A windows-1252 page with a BOM-less `<meta charset>` decodes to
   correct UTF-8; a header charset overrides the meta.
7. (7) A UTF-8 Hindi page declared ISO-8859-1 reads correctly with
   warning `charset_mismatch`.
8. (8) Decomposed Devanagari input comes out NFC; without intl, warning
   `no_normalizer`.
9. (9) `&nbsp;`, `&#2309;` decode; ZWJ in a Malayalam word survives; a
   U+200B is removed.
10. (10) A 4 MB body with a pathological pattern extracts fully; no
    content is lost.
11. (11) Unclosed `<p>`, stray `</div>` and `<section>` parse; body found.
12. (12) A remote DTD declaration triggers no network access (fake
    transport records zero calls).
13. (13) Text inside `nav`, `aside`, `footer` and `aria-hidden` never
    appears in body text.
14. (14) Each page type has a fixture that is classified correctly.
15. (15) `LiveBlogPosting` gives `liveblog`; `VideoObject` main entity
    gives `video`.
16. (16) A `/video/` path with no article data gives `video`; a `/video/`
    path with `NewsArticle` stays `article`.
17. (17) A section front of 40 headline links gives `index`.
18. (18) A playlist page returns title and image, empty body, warning
    `not_an_article`.
19. (19) A liveblog with five updates returns them in order;
    `modifiedAt` is the newest.
20. (20) A script-only shell gives `script_rendered`, `needs_javascript`.
21. (21) A page with all three candidates records each length in the
    trace.
22. (22) A related-stories block with high link density loses to the
    article block; danda-heavy Hindi prose scores as prose.
23. (23) Visible body of 150 chars plus a 2,000-char JSON-LD body: JSON-LD
    wins, warning `visible_body_short`.
24. (24) An author biography first in markup with a JSON-LD body: body is
    the JSON-LD text. Biography only: empty body, warning `biography_only`.
25. (25) Two unrelated long bodies: visible wins, `body_disagreement`,
    lower confidence, no merged text.
26. (26) "Also read" links, newsletter box and a repeated headline are
    gone from the body.
27. (27) `<a href="javascript:">`, `onclick`, `<iframe>` and `data:` image
    sources never reach body HTML.
28. (28) `<base href>` is used for relative links when present.
29. (29) Body text has blank lines between paragraphs and no share text.
30. (30) A 450-character Tamil article with few words passes the prose
    minimum.
31. (31) `src` spacer plus `data-src` real gives the real URL; `srcset`
    `2x` beats `1200w`; `<noscript>` image used when nothing else passes.
32. (32) `pixel.gif` and `1x1_spacer.png` are placeholders;
    `phone-pixel-10.jpg` is not.
33. (33) `<img width="19" height="23">` is furniture whatever its URL.
34. (34) `?size=50:50`, `w=80`, `/width-100/`, `SQUARE_80` are furniture;
    `/photo/1234567/` is not.
35. (35) `/assets/logo.png`, `icon.svg`, `share-btn.png` are furniture.
36. (36) The image pass is idempotent: second run output equals first.
37. (37) `og:image` spacer is skipped and the JSON-LD image chosen; with
    no JSON-LD, the first passing body image.
38. (38) A page with only furniture gives `leadImage` null,
    `no_lead_image`, and zero transport calls.
39. (39) JSON-LD headline beats `<title>`.
40. (40) `Story | Site` strips to `Story`; `Site: Budget` with a one-word
    remainder is kept whole.
41. (41) Entities and whitespace normalised in title.
42. (42) JSON-LD author list beats a byline element.
43. (43) "Web Desk" and "News Service" credits are `agency`; an `Organization` is
    `organisation`.
44. (44) "By A and B, Edited by C" gives authors A, B and editor C; a Hindi
    byline with "और" splits.
45. (45) Duplicate names merge; an email-address author is dropped with
    `bad_author`.
46. (46) No byline gives an empty list, not the site name.
47. (47) JSON-LD date beats `<time>`; modified read separately.
48. (48) `2026-11-14T10:30:00` gives 05:00 UTC with `assumed_timezone`.
49. (49) Each listed visible form parses to the right UTC instant; a Hindi
    month name parses.
50. (50) "2 hours ago" is null without a reference time and correct with
    one.
51. (51) A 2031 date and a 1970 date are rejected; modified before
    published is dropped.
52. (52) An undated page gives null, never today.
53. (53) A canonical pointing to the home page is ignored.
54. (54) An AMP page reports the non-AMP canonical and `ampUrl`.
55. (55) `utm_source` and the fragment are stripped; `id=7` is kept.
56. (56) A canonical on a declared own host is not reported, `own_host`.
57. (57) `m.example.in` gives host `example.in`; a custom normaliser
    replaces the rule.
58. (58) `lang="en"` with a Devanagari body sets `detectedScript` and
    `language_mismatch`.
59. (59) `og:site_name` beats the host.
60. (60) `isAccessibleForFree: false` with a short body gives
    `paywalled`; hidden paywalled text in the markup is not returned.
61. (61) A "Partner content" label is listed in `sponsoredSignals`; page
    type unchanged.
62. (62) Confidence falls with each listed warning; formula matches the
    documented one.
63. (63) `isReadable()` true for a clean article, false for each of
    `index`, short body, low confidence.
64. (64) With trace on, losing candidates and reasons are listed; off by
    default.
65. (65) Constructing and running `Extractor` over every fixture makes zero
    transport calls.
66. (66) Empty user agent and a `Mozilla/5.0` user agent raise
    `ConfigurationException`.
67. (67) A disallowed path raises `RobotsDisallowedException` and the page
    is never requested; `Allow` longer match wins; `$` anchor works.
68. (68) robots.txt `404` allows; `503` and timeout disallow; cache hit
    makes no second robots request within the TTL.
69. (69) `Crawl-delay: 30` stretches the interval; `Crawl-delay: 120`
    refuses.
70. (70) Two fetches to one host within 10 s: the second raises
    `RateLimitedException` with `retryAt`; another host is not blocked.
71. (71) `429` with `Retry-After: 120` blocks the host for 120 s; HTTP-date
    form parses; no automatic retry happens.
72. (72) `X-Robots-Tag: noarchive` gives `robotsRefused` and no body;
    `noimageai` drops only the lead image; `noindex` is report-only.
73. (73) A redirect to a disallowed path is refused; a loop raises
    `redirect_loop`; the sixth hop raises `too_many_redirects`.
74. (74) A host resolving to `169.254.169.254`, `10.0.0.1`, `::1` or
    `fd00::1` raises `BlockedAddressException`; port 8080 is refused.
75. (75) A `application/pdf` response raises `unexpected_content_type`; a
    6 MB body stops at 5 MB with `body_truncated`.
76. (76) `404` raises `HttpStatusException(404)`; a DNS failure raises
    `NetworkException`.
77. (77) No `Cookie` header is sent after a `Set-Cookie` response.
78. (78) `FetchedPage::toContext()` passes charset and robots into
    `extract()`.
79. (79) A fixture without `bodyText` scores other fields only.
80. (80) `meta.json` for a captured fixture without terms fields fails
    validation.
81. (81) The scorer run with a transport that throws on any call passes.
82. (82) Every synthetic fixture uses a reserved example host.
83. (83) A repo check fails if any fixture directory contains an image
    file.
84. (84) A coverage check lists each required pattern and language and
    fails if one has no fixture; fixture count is at least 40.
85. (85) Author precision and recall, date tolerance and body F1 are
    computed correctly on hand-built cases.
86. (86) Text, JSON and Markdown reports hold the same numbers.
87. (87) A one-fixture regression makes `score` exit 1; an improvement
    exits 0 and leaves the baseline unchanged.
88. (88) A fixture with invalid JSON makes `score` exit 2 and name it.

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

1. Q1. libxml's HTML4 parser versus a pure-PHP HTML5 tokenizer: how are
   HTML5 elements and encoding quirks handled on 8.1 without a runtime
   dependency? (PHP 8.4's HTML5 parser can be used when present.)
2. Q2. The exact body scoring weights and the sibling-join rule, tuned on
   the fixture suite and recorded with their scores.
3. Q3. The token rule for body F1 across scripts (whitespace tokens for
   most; what for scripts and texts with no spaces).
4. Q4. Whether to ship a file-based `RateLimitStore` and `RobotsCache` for
   multi-process use, and its locking.
5. Q5. Month names in scripts other than Latin and Devanagari: which to
   support at 0.1.0.
6. Q6. The confidence formula (62): weights per warning.
7. Q7. How DNS pinning is done with curl (`CURLOPT_RESOLVE`) and the
   fallback when a custom transport is used.
8. Q8. Fixture coverage map format and how the coverage check reads it.

## 14. Lessons from production

Written as rules.

1. Never trust the first block of prose. A short author or desk biography
   can sit where the article should be. Measure it against the prose
   minimum and the structured body before accepting it.
2. Structured data is a fallback for a short visible body, not a blend.
   Pick one source, record which, and never stitch two texts together.
3. A wrong body is worse than an empty one. When in doubt return empty
   with a reason, and give the caller a confidence it can hold on.
4. Plain `src` on a lazy-loading page is often a spacer. Read the lazy
   attributes and `srcset` first.
5. Match placeholder names on the whole file name, never a substring, or
   real photos whose names contain "pixel" vanish.
6. An image tag's own small `width` and `height` mean furniture, even when
   the URL looks like a photo.
7. Read sizes from a URL only in explicit, labelled forms. Never guess a
   size from a numeric id.
8. Logos, icons, SVGs and share buttons are furniture. Filter them before
   taking "the first image".
9. Apply the same image tests to `og:image`. The fallback can itself be a
   spacer.
10. Video pages, playlists and section fronts are not articles. Their text
    is whichever card was showing. Classify before extracting.
11. Never fill a missing date with "now". Undated index pages carry old
    content, and a fake date makes it look fresh.
12. Do not run regular expressions over a whole page. Large bodies hit the
    backtrack limit, and a failed match must never lose content.
13. Make every normalising pass idempotent, so running it twice changes
    nothing.
14. The source URL must be the publisher's. Refuse the caller's own hosts,
    and leave aggregator wrapper links to a resolver.
15. Mirror hosts (`www.`, `amp.`, `m.`) are one outlet, and the canonical
    beats the AMP copy.
16. Tracking parameters and fragments are not part of an article's
    identity.
17. Pay the network cost once. Fetch only when asked, cache robots.txt,
    and leave page caching to the caller who owns the store.
18. Saved pages are not a regression guard until they are scored against a
    committed baseline that CI checks.
