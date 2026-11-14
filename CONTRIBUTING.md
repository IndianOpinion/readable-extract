# Contributing

Thank you for helping. Bug reports with a small HTML sample are the most
useful thing you can send.

## Setup

```sh
git clone https://github.com/IndianOpinion/readable-extract.git
cd readable-extract
composer install
```

PHP 8.1 or later with `ext-dom`, `ext-libxml` and `ext-mbstring`.
`ext-intl` is optional; CI runs with it.

## Checks

Run all of these before you open a pull request:

```sh
composer test     # PHPUnit
composer stan     # PHPStan, level 8
composer cs       # PHP_CodeSniffer, PSR-12
composer score    # fixture suite against the committed baseline
```

All tests run offline. Fetch tests use a scripted transport, a fake DNS
resolver and a fake clock; never add a test that needs the network.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- Code must run on PHP 8.1: no readonly classes, no DNF types, no `true`
  type, no constants in traits.
- Typed exceptions, never silent failure, never `@`, no `eval`, no shell.
- Prose in British English, short sentences, no em dashes.
- No regular expression over a whole page. Run them on one field or one
  text node.

## Fixtures

- Create a skeleton with `bin/readable-extract fixture --new my-case`.
- Synthetic fixtures are the default. Write original placeholder text, use
  reserved hosts only (`example.com`, `*.example`, `*.test`) and a generic
  outlet label such as "regional daily, Tamil". Never name a real outlet.
- A captured page is allowed only where the outlet's published terms allow
  keeping a copy for this purpose. Replace its prose with original text of
  similar length and script, rewrite every host to a reserved one, replace
  names, logos and mastheads with generic text, and fill in
  `termsCheckedOn` and `termsNote`. Keep the terms page address in your
  own records, not in the repository.
- Never commit an image. Image URLs stay as text.
- Run the tests: they rewrite `docs/fixture-coverage.json`. Commit it.

## Baseline

`composer score` fails when accuracy drops. If your change improves the
scores, record the new baseline with
`bin/readable-extract baseline --write fixtures` and say why in the pull
request. Never rewrite the baseline to hide a regression.

## Pull requests

- One topic per pull request, with tests for the behaviour you change.
- Fill in the pull request template, including the score summary.
- Sign-off lines are not required.
- By contributing you agree your work is released under the MIT licence.
