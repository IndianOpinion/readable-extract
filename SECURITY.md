# Security policy

## Reporting a vulnerability

Email **hello@indianopinion.org** with "readable-extract security" in the
subject. Please do not open a public issue for a security problem.

Include the version or commit, a minimal HTML input or fetch scenario that
shows the problem, and what you expected. We reply within 3 working days,
aim to confirm or rule out the issue within 10 working days, and credit
you in the release notes unless you prefer not to be named.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Scope

In scope, for example:

- HTML that makes `bodyHtml` carry script, event handlers, `javascript:`,
  `data:` or `vbscript:` URLs, iframes or other markup outside the
  allowlist.
- Input that makes extraction touch the network, load an external entity
  or DTD, or write a file.
- A fetch that reaches a private, loopback, link-local, reserved or
  metadata address, a port outside the allowed list, or a path robots.txt
  disallows, including through redirects.
- A fetch that sends cookies, or an Authorization header the caller did
  not set.

Known and documented, not a vulnerability: the DNS time-of-check gap
described in `docs/ARCHITECTURE.md`, decision 7. A custom transport that
connects to the checked address closes it.

Callers should still sanitise `bodyHtml` with their own renderer's
sanitiser before display.
