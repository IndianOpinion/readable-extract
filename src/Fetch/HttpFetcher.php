<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Exception\BlockedAddressException;
use IndianOpinion\ReadableExtract\Exception\FetchException;
use IndianOpinion\ReadableExtract\Exception\HttpStatusException;
use IndianOpinion\ReadableExtract\Exception\NetworkException;
use IndianOpinion\ReadableExtract\Exception\RateLimitedException;
use IndianOpinion\ReadableExtract\Exception\RobotsDisallowedException;
use IndianOpinion\ReadableExtract\Internal\Url;

/**
 * Polite, explicit page fetcher (cases 65 to 78).
 *
 * Before every request, including every redirect hop: the URL scheme and
 * port are checked, the host is resolved and every address is checked
 * against AddressPolicy, robots.txt is consulted (no off switch), and the
 * per-host rate limiter must grant a slot. 429 and 503 with Retry-After
 * block the host. Nothing is retried automatically. No cookies are kept.
 */
final class HttpFetcher implements Fetcher
{
    private const REDIRECTS = [301, 302, 303, 307, 308];

    private Transport $transport;
    private RobotsCache $robotsCache;
    private RateLimitStore $limits;
    private Resolver $resolver;
    private Clock $clock;
    /** Seconds spent in the transport during the current fetch(), across every hop and robots.txt read. */
    private float $spent = 0.0;
    /** @var (\Closure(string, string, array<string, mixed>): void)|null */
    private ?\Closure $logger;

    /**
     * @param (callable(string, string, array<string, mixed>): void)|null $logger receives (level, message, context)
     */
    public function __construct(
        private readonly FetchOptions $options,
        ?Transport $transport = null,
        ?RobotsCache $robotsCache = null,
        ?RateLimitStore $limits = null,
        ?callable $logger = null,
    ) {
        $this->transport = $transport ?? new CurlTransport();
        $this->robotsCache = $robotsCache ?? new InMemoryRobotsCache();
        $this->limits = $limits ?? new InMemoryRateLimitStore();
        $this->resolver = $options->resolver();
        $this->clock = $options->clock();
        $this->logger = $logger !== null ? \Closure::fromCallable($logger) : null;
    }

    public function fetch(string $url): FetchedPage
    {
        Url::assertInput($url);
        $authOrigin = $this->origin($url);
        $this->spent = 0.0;
        $current = $url;
        $hops = [];
        $visited = [];
        $acquired = [];
        while (true) {
            if (isset($visited[$current])) {
                $this->log('warning', 'redirect loop', ['url' => $current]);
                throw new FetchException(FetchException::REDIRECT_LOOP, 'Redirect loop at ' . $current);
            }
            $visited[$current] = true;
            $this->checkNetwork($current);
            $host = (string) Url::host($current);
            $robots = $this->robotsFor($current, $authOrigin);
            $target = $this->pathAndQuery($current);
            if (!$robots->isAllowed($this->options->productToken(), $target)) {
                $this->log('info', 'robots.txt disallows', ['url' => $current, 'robots' => $robots->status]);
                throw new RobotsDisallowedException('robots.txt does not allow ' . $current . ' (' . $robots->status . ')');
            }
            $interval = $this->options->hostInterval();
            $delay = $robots->crawlDelay($this->options->productToken());
            if ($delay !== null) {
                if ($delay > $this->options->maxCrawlDelay()) {
                    $this->log('info', 'crawl delay above the maximum', ['host' => $host, 'crawl_delay' => $delay]);
                    throw new RateLimitedException($host, null, 'Crawl-delay ' . $delay . ' s is above the maximum for ' . $host);
                }
                $interval = max($interval, $delay);
            }
            if (!isset($acquired[$host])) {
                $this->acquire($host, $interval);
                $acquired[$host] = true;
            }
            $response = $this->send($current, $this->options->bodyCap(), $authOrigin);
            $status = $response->status;
            $this->log('info', 'response', ['url' => $current, 'status' => $status, 'bytes' => strlen($response->body)]);

            if (in_array($status, self::REDIRECTS, true)) {
                $location = $response->header('location');
                if ($location === null || trim($location) === '') {
                    throw new HttpStatusException($status, $current);
                }
                $next = Url::resolve($current, $location);
                if ($next === null || !Url::isHttp($next)) {
                    throw new FetchException(FetchException::BAD_REDIRECT, 'Redirect to a non-http URL from ' . $current);
                }
                $next = $this->withoutFragment($next);
                $hops[] = $current;
                if (count($hops) > $this->options->maxRedirects()) {
                    $this->log('warning', 'too many redirects', ['url' => $current]);
                    throw new FetchException(FetchException::TOO_MANY_REDIRECTS, 'More than ' . $this->options->maxRedirects() . ' redirects');
                }
                $this->log('info', 'redirect', ['from' => $current, 'to' => $next]);
                $current = $next;
                continue;
            }
            if ($status === 429 || ($status === 503 && $response->header('retry-after') !== null)) {
                $until = $this->retryAt($response->header('retry-after'));
                $this->limits->block($host, $until);
                $retry = (new \DateTimeImmutable('@' . (int) ceil($until)));
                $this->log('warning', 'host asked us to back off', ['host' => $host, 'status' => $status, 'until' => $retry->format(DATE_ATOM)]);
                throw new RateLimitedException($host, $retry, 'HTTP ' . $status . ' from ' . $host);
            }
            if ($status < 200 || $status >= 300) {
                throw new HttpStatusException($status, $current);
            }
            $type = strtolower(trim(explode(';', (string) $response->header('content-type'))[0]));
            if ($type !== 'text/html' && $type !== 'application/xhtml+xml') {
                throw new FetchException(FetchException::UNEXPECTED_CONTENT_TYPE, 'Unexpected content type "' . $type . '" at ' . $current);
            }
            $directives = RobotsDirectives::fromResponse(
                $response->headers['x-robots-tag'] ?? [],
                $response->body,
                $this->options->productToken(),
                $this->options->honourRobotsMeta(),
            );
            if ($response->truncated) {
                $this->log('warning', 'body truncated at the cap', ['url' => $current, 'cap' => $this->options->bodyCap()]);
            }
            return new FetchedPage(
                finalUrl: $current,
                status: $status,
                headers: $response->headers,
                charset: FetchedPage::charsetFrom($response->header('content-type')),
                body: $response->body,
                hops: $hops,
                robots: $directives,
                robotsRefused: $directives->refusesText(),
                truncated: $response->truncated,
            );
        }
    }

    private function checkNetwork(string $url): void
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            throw new FetchException(FetchException::BAD_REDIRECT, 'Not an absolute URL: ' . $url);
        }
        $scheme = strtolower($p['scheme']);
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, $this->options->allowedPorts(), true)) {
            throw new BlockedAddressException('Port ' . $port . ' is not allowed for ' . $p['host']);
        }
        if ($this->options->allowPrivateAddresses()) {
            return;
        }
        $ips = $this->resolver->resolve($p['host']);
        if ($ips === []) {
            throw new NetworkException('No address for ' . $p['host']);
        }
        foreach ($ips as $ip) {
            if (AddressPolicy::isBlocked($ip)) {
                $this->log('warning', 'blocked address', ['host' => $p['host'], 'ip' => $ip]);
                throw new BlockedAddressException($p['host'] . ' resolves to a blocked address ' . $ip);
            }
        }
    }

    private function robotsFor(string $url, string $authOrigin): RobotsTxt
    {
        $origin = $this->origin($url);
        $now = $this->clock->now();
        $cached = $this->robotsCache->get($origin, $now);
        if ($cached !== null) {
            return $cached;
        }
        $robotsUrl = $origin . '/robots.txt';
        $seen = [];
        try {
            for ($hop = 0; $hop <= 5; $hop++) {
                if (isset($seen[$robotsUrl])) {
                    break;
                }
                $seen[$robotsUrl] = true;
                $this->checkNetwork($robotsUrl);
                $r = $this->send($robotsUrl, RobotsTxt::MAX_BYTES, $authOrigin);
                if (in_array($r->status, self::REDIRECTS, true) && $r->header('location') !== null) {
                    $next = Url::resolve($robotsUrl, (string) $r->header('location'));
                    if ($next === null || !Url::isHttp($next)) {
                        break;
                    }
                    $robotsUrl = $next;
                    continue;
                }
                if ($r->status >= 200 && $r->status < 300) {
                    $robots = RobotsTxt::parse($r->body);
                } elseif ($r->status >= 400 && $r->status < 500) {
                    $robots = RobotsTxt::allowAll('unavailable_4xx');
                } else {
                    $this->log('warning', 'robots.txt unreachable', ['origin' => $origin, 'status' => $r->status]);
                    return RobotsTxt::disallowAll('unreachable_' . $r->status);
                }
                $this->robotsCache->set($origin, $robots, $now + $this->options->robotsTtl());
                $this->log('info', 'robots.txt read', ['origin' => $origin, 'status' => $r->status]);
                return $robots;
            }
        } catch (NetworkException | BlockedAddressException $e) {
            $this->log('warning', 'robots.txt unreachable', ['origin' => $origin, 'reason' => $e->getMessage()]);
            return RobotsTxt::disallowAll('unreachable');
        }
        $this->log('warning', 'robots.txt redirects did not settle', ['origin' => $origin]);
        return RobotsTxt::disallowAll('unreachable_redirects');
    }

    private function acquire(string $host, float $interval): void
    {
        $now = $this->clock->now();
        $open = $this->limits->acquire($host, $now, $interval);
        if ($open === null) {
            return;
        }
        $wait = $open - $now;
        if ($wait <= $this->options->waitBudget()) {
            $this->log('info', 'waiting for a rate-limit slot', ['host' => $host, 'seconds' => round($wait, 3)]);
            $this->clock->sleep($wait);
            $now = $this->clock->now();
            $open = $this->limits->acquire($host, $now, $interval);
            if ($open === null) {
                return;
            }
        }
        $retry = new \DateTimeImmutable('@' . (int) ceil($open));
        $this->log('info', 'rate limited', ['host' => $host, 'retry_at' => $retry->format(DATE_ATOM)]);
        throw new RateLimitedException($host, $retry, 'Next slot for ' . $host . ' opens at ' . $retry->format(DATE_ATOM));
    }

    /**
     * The Authorization header goes only to the origin (scheme, host and
     * port) of the URL the caller asked for: a redirect to another host,
     * or a downgrade from https to http, never carries the credential.
     */
    private function send(string $url, int $cap, string $authOrigin): TransportResponse
    {
        $headers = [
            'User-Agent' => $this->options->userAgent(),
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
        ];
        if ($this->options->authorization() !== null && $this->origin($url) === $authOrigin) {
            $headers['Authorization'] = (string) $this->options->authorization();
        }
        // The total timeout covers the whole fetch, not each hop, so a chain
        // of slow redirects cannot hold the caller for hops x timeout.
        $left = $this->options->totalTimeout() - $this->spent;
        if ($left < 0.001) {
            throw new NetworkException('Total time limit of ' . $this->options->totalTimeout() . ' s reached before ' . $url);
        }
        $start = hrtime(true);
        try {
            return $this->transport->send(new TransportRequest(
                $url,
                $headers,
                min($this->options->connectTimeout(), $left),
                $left,
                $cap,
            ));
        } finally {
            $this->spent += (hrtime(true) - $start) / 1e9;
        }
    }

    private function retryAt(?string $header): float
    {
        $now = $this->clock->now();
        if ($header !== null) {
            $h = trim($header);
            if (ctype_digit($h)) {
                return $now + min((float) $h, 7.0 * 86400);
            }
            $d = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $h, new \DateTimeZone('UTC'));
            if ($d instanceof \DateTimeImmutable) {
                return max($now, min((float) $d->getTimestamp(), $now + 7.0 * 86400));
            }
        }
        return $now + $this->options->defaultBackoff();
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $host = strtolower((string) ($p['host'] ?? ''));
        $port = $p['port'] ?? null;
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }
        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    private function pathAndQuery(string $url): string
    {
        $p = parse_url($url);
        $path = (string) ($p['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        return isset($p['query']) ? $path . '?' . $p['query'] : $path;
    }

    private function withoutFragment(string $url): string
    {
        $hash = strpos($url, '#');
        return $hash === false ? $url : substr($url, 0, $hash);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
