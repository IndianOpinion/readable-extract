# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- `Extractor` for offline extraction of body, title, authors, editors,
  dates, lead image, canonical and AMP URLs, host, language, script, site
  name, access and sponsored signals, with per-field sources, confidence
  and an opt-in trace.
- Page types: article, liveblog, video, gallery, index, paywalled,
  script_rendered, wrapper, empty and unknown.
- Character set detection with UTF-8 mismatch handling, NFC
  normalisation, Indic-aware length tests and word counts.
- Lazy image, srcset and noscript handling with placeholder and furniture
  tests that match whole names only.
- Visible date parsing for common Indian formats, Hindi and Marathi month
  names and Indic digits.
- `HttpFetcher`: RFC 9309 robots.txt with no off switch, per-host rate
  limits, crawl delays, Retry-After, robots meta and header directives,
  private address refusal on every hop, no cookies.
- Fixture suite of 56 synthetic pages across eleven languages, a scorer
  with text, JSON and Markdown reports, and a committed baseline.
- `bin/readable-extract` with `extract`, `score`, `baseline` and
  `fixture` commands.
- `bootstrap.php` for use without Composer.

[Unreleased]: https://github.com/IndianOpinion/readable-extract/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/readable-extract/releases/tag/v0.1.0
