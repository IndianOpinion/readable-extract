<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Fetch;

use IndianOpinion\ReadableExtract\Exception\BlockedAddressException;
use IndianOpinion\ReadableExtract\Exception\ConfigurationException;
use IndianOpinion\ReadableExtract\Exception\FetchException;
use IndianOpinion\ReadableExtract\Exception\HttpStatusException;
use IndianOpinion\ReadableExtract\Exception\NetworkException;
use IndianOpinion\ReadableExtract\Exception\RateLimitedException;
use IndianOpinion\ReadableExtract\Exception\RobotsDisallowedException;
use IndianOpinion\ReadableExtract\Extractor;
use IndianOpinion\ReadableExtract\Fetch\AddressPolicy;
use IndianOpinion\ReadableExtract\Fetch\FetchOptions;
use IndianOpinion\ReadableExtract\Fetch\HttpFetcher;
use IndianOpinion\ReadableExtract\Fetch\InMemoryRateLimitStore;
use IndianOpinion\ReadableExtract\Fetch\InMemoryRobotsCache;
use IndianOpinion\ReadableExtract\Fetch\RobotsTxt;
use IndianOpinion\ReadableExtract\Fetch\TransportResponse;
use IndianOpinion\ReadableExtract\Fixtures\Suite;
use IndianOpinion\ReadableExtract\ReadableExtract;
use IndianOpinion\ReadableExtract\Tests\Support\FakeClock;
use IndianOpinion\ReadableExtract\Tests\Support\FakeResolver;
use IndianOpinion\ReadableExtract\Tests\Support\NoNetwork;
use IndianOpinion\ReadableExtract\Tests\Support\Page;
use IndianOpinion\ReadableExtract\Tests\Support\ScriptedTransport;
use IndianOpinion\ReadableExtract\Warning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRD cases 65 to 78. Offline: scripted transport, fake DNS, fake clock. */
final class FetcherTest extends TestCase
{
    private const UA = 'ExampleReader/1.0 (+https://reader.example/contact)';
    private const ROBOTS = 'https://www.daily.example/robots.txt';
    private const PAGE = 'https://www.daily.example/city/story-1';

    private FakeClock $clock;
    private ScriptedTransport $transport;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->transport = new ScriptedTransport();
    }

    private function options(array $dns = []): FetchOptions
    {
        return (new FetchOptions(self::UA))->withClock($this->clock)->withResolver(new FakeResolver($dns));
    }

    /** @param list<array{0: string, 1: string, 2: array<string, mixed>}> $log */
    private function fetcher(?FetchOptions $o = null, ?InMemoryRateLimitStore $limits = null, ?InMemoryRobotsCache $cache = null, array &$log = []): HttpFetcher
    {
        return new HttpFetcher($o ?? $this->options(), $this->transport, $cache, $limits, static function (string $level, string $msg, array $ctx) use (&$log): void {
            $log[] = [$level, $msg, $ctx];
        });
    }

    private function page(string $body = '', array $headers = []): TransportResponse
    {
        return ScriptedTransport::html($body !== '' ? $body : Page::article(Page::prose()), 200, $headers);
    }

    public function testCase65ExtractionOverEveryFixtureMakesNoNetworkCall(): void
    {
        $suite = Suite::load(__DIR__ . '/../../fixtures');
        NoNetwork::install();
        try {
            $e = new Extractor();
            foreach ($suite->fixtures as $f) {
                $e->extract($f->html, $f->url());
            }
        } finally {
            NoNetwork::uninstall();
        }
        self::assertSame([], NoNetwork::$attempts);
        self::assertSame([], $this->transport->requests);
    }

    public function testCase65ConstructingAFetcherSendsNothing(): void
    {
        $this->fetcher();
        self::assertSame([], $this->transport->requests);
    }

    #[DataProvider('badAgents')]
    public function testCase66UserAgentRules(string $ua): void
    {
        $this->expectException(ConfigurationException::class);
        new FetchOptions($ua);
    }

    /** @return array<string, array{string}> */
    public static function badAgents(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'browser' => ['Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/120.0'],
            'no contact' => ['ExampleReader/1.0'],
        ];
    }

    public function testCase66ProductTokenIsFirstToken(): void
    {
        self::assertSame('examplereader', (new FetchOptions(self::UA))->productToken());
    }

    public function testCase67DisallowedPathIsNeverRequested(): void
    {
        $this->transport->on(self::ROBOTS, ScriptedTransport::robots("User-agent: *\nDisallow: /city/\n"));
        try {
            $this->fetcher()->fetch(self::PAGE);
            self::fail('expected RobotsDisallowedException');
        } catch (RobotsDisallowedException) {
        }
        self::assertSame([self::ROBOTS], $this->transport->urls());
    }

    public function testCase67LongestMatchAllowTieAndDollarAnchor(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /city/\nAllow: /city/open/\nDisallow: /*.pdf$\nAllow: /tie\nDisallow: /tie\n\nUser-agent: ExampleReader\nDisallow: /private\n");
        self::assertFalse($r->isAllowed('otherbot', '/city/closed'));
        self::assertTrue($r->isAllowed('otherbot', '/city/open/story'));
        self::assertFalse($r->isAllowed('otherbot', '/files/report.pdf'));
        self::assertTrue($r->isAllowed('otherbot', '/files/report.pdf?x=1'));
        self::assertTrue($r->isAllowed('otherbot', '/tie'));
        self::assertTrue($r->isAllowed('examplereader', '/city/closed'), 'own group replaces *');
        self::assertFalse($r->isAllowed('examplereader', '/private/x'));
        self::assertTrue($r->isAllowed('otherbot', '/robots.txt'));
    }

    public function testCase68RobotsStatusHandlingAndCache(): void
    {
        // 404: no restrictions.
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], 'nope'));
        $this->transport->on(self::PAGE, $this->page());
        $cache = new InMemoryRobotsCache();
        $f = $this->fetcher(null, new InMemoryRateLimitStore(), $cache);
        $f->fetch(self::PAGE);
        $this->clock->advance(11);
        $f->fetch(self::PAGE);
        self::assertSame([self::ROBOTS, self::PAGE, self::PAGE], $this->transport->urls(), 'robots fetched once within the TTL');
        $this->clock->advance(86400);
        $f->fetch(self::PAGE);
        self::assertSame(self::ROBOTS, $this->transport->urls()[3], 'robots fetched again after the TTL');
    }

    public function testCase68ServerErrorAndTimeoutDisallow(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(503, [], ''), new NetworkException('timed out'));
        $f = $this->fetcher();
        foreach ([1, 2] as $attempt) {
            try {
                $f->fetch(self::PAGE);
                self::fail('expected RobotsDisallowedException on attempt ' . $attempt);
            } catch (RobotsDisallowedException) {
            }
        }
        self::assertSame([self::ROBOTS, self::ROBOTS], $this->transport->urls(), 'not cached: retried on the next attempt');
    }

    public function testCase68RobotsRedirectsFollowedAndBodyCapped(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(301, ['location' => 'https://cdn.daily.example/robots.txt'], ''));
        $this->transport->on('https://cdn.daily.example/robots.txt', ScriptedTransport::robots("User-agent: *\nDisallow: /city/\n" . str_repeat('#', 600000)));
        $this->expectException(RobotsDisallowedException::class);
        try {
            $this->fetcher()->fetch(self::PAGE);
        } finally {
            self::assertLessThanOrEqual(RobotsTxt::MAX_BYTES, $this->transport->requests[1]->bodyCap);
        }
    }

    public function testCase69CrawlDelayStretchesIntervalAndLongDelayRefuses(): void
    {
        $this->transport->on(self::ROBOTS, ScriptedTransport::robots("User-agent: *\nCrawl-delay: 30\n"));
        $this->transport->on(self::PAGE, $this->page());
        $f = $this->fetcher();
        $f->fetch(self::PAGE);
        $this->clock->advance(20);
        try {
            $f->fetch(self::PAGE);
            self::fail('expected RateLimitedException inside the 30 s crawl delay');
        } catch (RateLimitedException $e) {
            self::assertSame((int) ceil($this->clock->now + 10), $e->retryAt?->getTimestamp());
        }
        $this->clock->advance(10);
        $f->fetch(self::PAGE);

        $t2 = new ScriptedTransport();
        $t2->on(self::ROBOTS, ScriptedTransport::robots("User-agent: *\nCrawl-delay: 120\n"));
        $this->expectException(RateLimitedException::class);
        try {
            (new HttpFetcher($this->options(), $t2))->fetch(self::PAGE);
        } finally {
            self::assertSame([self::ROBOTS], $t2->urls());
        }
    }

    public function testCase70PerHostRateLimit(): void
    {
        $other = 'https://www.other.example/a';
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on('https://www.other.example/robots.txt', new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, $this->page());
        $this->transport->on($other, $this->page());
        $f = $this->fetcher();
        $f->fetch(self::PAGE);
        $this->clock->advance(4);
        try {
            $f->fetch(self::PAGE);
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame('www.daily.example', $e->host);
            self::assertSame((int) ceil($this->clock->now + 6), $e->retryAt?->getTimestamp());
        }
        $f->fetch($other);
        self::assertSame([], $this->clock->sleeps);
    }

    public function testCase70WaitBudgetSleepsForTheSlot(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, $this->page());
        $f = $this->fetcher($this->options()->withWaitBudget(15));
        $f->fetch(self::PAGE);
        $this->clock->advance(4);
        $f->fetch(self::PAGE);
        self::assertSame([6.0], $this->clock->sleeps);
    }

    public function testCase71RetryAfterBlocksTheHostWithoutRetry(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, new TransportResponse(429, ['retry-after' => '120'], ''));
        $f = $this->fetcher();
        try {
            $f->fetch(self::PAGE);
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame((int) ceil($this->clock->now + 120), $e->retryAt?->getTimestamp());
        }
        self::assertSame([self::ROBOTS, self::PAGE], $this->transport->urls(), 'no automatic retry');
        $this->clock->advance(60);
        try {
            $f->fetch(self::PAGE);
            self::fail('host still blocked');
        } catch (RateLimitedException) {
        }
        self::assertCount(2, $this->transport->requests);
    }

    public function testCase71HttpDateAndDefaultBackoff(): void
    {
        $when = gmdate('D, d M Y H:i:s \G\M\T', (int) $this->clock->now + 300);
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, new TransportResponse(503, ['retry-after' => $when], ''));
        try {
            $this->fetcher()->fetch(self::PAGE);
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame((int) $this->clock->now + 300, $e->retryAt?->getTimestamp());
        }
        $t = new ScriptedTransport();
        $t->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $t->on(self::PAGE, new TransportResponse(429, [], ''));
        try {
            (new HttpFetcher($this->options(), $t))->fetch(self::PAGE);
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame((int) $this->clock->now + 600, $e->retryAt?->getTimestamp());
        }
    }

    public function testCase72RobotsHeaderAndMetaDirectives(): void
    {
        $html = Page::article(Page::prose(), ['image' => 'https://img.daily.example/lead.jpg']);
        $e = new Extractor();

        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, $this->page($html, ['x-robots-tag' => 'noarchive']));
        $p = $this->fetcher()->fetch(self::PAGE);
        self::assertTrue($p->robotsRefused);
        $a = $e->extract($p->body, $p->finalUrl, $p->toContext());
        self::assertSame('', $a->bodyText);
        self::assertTrue($a->hasWarning(Warning::RobotsMeta));

        $html2 = str_replace('<head>', '<head><meta name="robots" content="noimageai">', $html);
        $t2 = (new ScriptedTransport())->on(self::ROBOTS, new TransportResponse(404, [], ''))->on(self::PAGE, $this->page($html2));
        $p2 = (new HttpFetcher($this->options(), $t2))->fetch(self::PAGE);
        self::assertFalse($p2->robotsRefused);
        $b = $e->extract($p2->body, $p2->finalUrl, $p2->toContext());
        self::assertNull($b->leadImage);
        self::assertNotSame('', $b->bodyText);

        $t3 = (new ScriptedTransport())->on(self::ROBOTS, new TransportResponse(404, [], ''))->on(self::PAGE, $this->page($html, ['x-robots-tag' => ['noindex', 'otherbot: noarchive']]));
        $p3 = (new HttpFetcher($this->options(), $t3))->fetch(self::PAGE);
        self::assertFalse($p3->robotsRefused);
        self::assertTrue($p3->robots->noindex());
        self::assertFalse($p3->robots->has('noarchive'), 'directive for another bot is ignored');

        $html4 = str_replace('<head>', '<head><meta name="examplereader" content="nosnippet">', $html);
        $t4 = (new ScriptedTransport())->on(self::ROBOTS, new TransportResponse(404, [], ''))->on(self::PAGE, $this->page($html4));
        self::assertTrue((new HttpFetcher($this->options(), $t4))->fetch(self::PAGE)->robotsRefused, 'product meta name');
    }

    public function testCase72HonouredListCanGrowNotShrink(): void
    {
        $o = $this->options()->withHonourRobotsMeta(['none', 'noarchive', 'nosnippet', 'noai', 'noimageai', 'noindex']);
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, $this->page('', ['x-robots-tag' => 'noindex']));
        self::assertTrue($this->fetcher($o)->fetch(self::PAGE)->robotsRefused);
        $this->expectException(ConfigurationException::class);
        $this->options()->withHonourRobotsMeta(['noai']);
    }

    public function testCase73RedirectToDisallowedPathRefused(): void
    {
        $this->transport->on(self::ROBOTS, ScriptedTransport::robots("User-agent: *\nDisallow: /private/\n"));
        $this->transport->on(self::PAGE, new TransportResponse(302, ['location' => '/private/story'], ''));
        $this->expectException(RobotsDisallowedException::class);
        try {
            $this->fetcher()->fetch(self::PAGE);
        } finally {
            self::assertNotContains('https://www.daily.example/private/story', $this->transport->urls());
        }
    }

    public function testCase73RedirectLoopAndTooManyHops(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, new TransportResponse(301, ['location' => 'https://www.daily.example/b'], ''));
        $this->transport->on('https://www.daily.example/b', new TransportResponse(302, ['location' => self::PAGE], ''));
        try {
            $this->fetcher()->fetch(self::PAGE);
            self::fail('expected redirect_loop');
        } catch (FetchException $e) {
            self::assertSame(FetchException::REDIRECT_LOOP, $e->reason);
        }

        $t = new ScriptedTransport();
        $t->on(self::ROBOTS, new TransportResponse(404, [], ''));
        for ($i = 0; $i < 7; $i++) {
            $t->on('https://www.daily.example/h' . $i, new TransportResponse(301, ['location' => 'https://www.daily.example/h' . ($i + 1)], ''));
        }
        try {
            (new HttpFetcher($this->options(), $t))->fetch('https://www.daily.example/h0');
            self::fail('expected too_many_redirects');
        } catch (FetchException $e) {
            self::assertSame(FetchException::TOO_MANY_REDIRECTS, $e->reason);
        }
        $pages = array_values(array_filter($t->urls(), static fn (string $u): bool => !str_ends_with($u, 'robots.txt')));
        self::assertCount(6, $pages, 'the sixth redirect response is the one refused');
    }

    public function testCase73RedirectFollowedWithHops(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on('http://www.daily.example/robots.txt', new TransportResponse(404, [], ''));
        $this->transport->on('http://www.daily.example/city/story-1', new TransportResponse(301, ['location' => self::PAGE . '#frag'], ''));
        $this->transport->on(self::PAGE, $this->page());
        $p = $this->fetcher()->fetch('http://www.daily.example/city/story-1');
        self::assertSame(self::PAGE, $p->finalUrl);
        self::assertSame(['http://www.daily.example/city/story-1'], $p->hops);
    }

    #[DataProvider('blockedAddresses')]
    public function testCase74BlockedAddresses(string $ip): void
    {
        $this->expectException(BlockedAddressException::class);
        try {
            $this->fetcher($this->options(['www.daily.example' => [$ip]]))->fetch(self::PAGE);
        } finally {
            self::assertSame([], $this->transport->requests, 'nothing sent, not even robots.txt');
        }
    }

    /** @return array<string, array{string}> */
    public static function blockedAddresses(): array
    {
        return [
            'metadata' => ['169.254.169.254'],
            'private' => ['10.0.0.1'],
            'loopback v6' => ['::1'],
            'unique local' => ['fd00::1'],
            'loopback v4' => ['127.0.0.1'],
            'mapped private' => ['::ffff:192.168.1.1'],
            'cgnat' => ['100.64.0.1'],
            'multicast' => ['224.0.0.1'],
        ];
    }

    public function testCase74PolicyAllowsPublicAddresses(): void
    {
        self::assertFalse(AddressPolicy::isBlocked(FakeResolver::PUBLIC));
        self::assertFalse(AddressPolicy::isBlocked('2600::1'));
        self::assertTrue(AddressPolicy::isBlocked('not-an-ip'));
    }

    public function testCase74PortAndPrivateOverride(): void
    {
        try {
            $this->fetcher()->fetch('https://www.daily.example:8080/a');
            self::fail('expected BlockedAddressException for port 8080');
        } catch (BlockedAddressException) {
        }
        $o = $this->options(['localhost.test' => ['127.0.0.1']])->withAllowPrivateAddresses(true)->withAllowedPorts([8080]);
        $this->transport->on('http://localhost.test:8080/robots.txt', new TransportResponse(404, [], ''));
        $this->transport->on('http://localhost.test:8080/a', $this->page());
        self::assertSame(200, $this->fetcher($o)->fetch('http://localhost.test:8080/a')->status);
    }

    public function testCase75ContentTypeAndBodyCap(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, new TransportResponse(200, ['content-type' => 'application/pdf'], '%PDF'));
        try {
            $this->fetcher()->fetch(self::PAGE);
            self::fail('expected unexpected_content_type');
        } catch (FetchException $e) {
            self::assertSame(FetchException::UNEXPECTED_CONTENT_TYPE, $e->reason);
        }
        $big = Page::article(Page::prose()) . str_repeat('<p>' . str_repeat('x', 1000) . '</p>', 6 * 1024);
        $t = (new ScriptedTransport())->on(self::ROBOTS, new TransportResponse(404, [], ''))->on(self::PAGE, $this->page($big));
        $p = (new HttpFetcher($this->options(), $t))->fetch(self::PAGE);
        self::assertTrue($p->truncated);
        self::assertSame(5 * 1024 * 1024, strlen($p->body));
        self::assertSame(5 * 1024 * 1024, $t->requests[1]->bodyCap);
        self::assertTrue((new Extractor())->extract($p->body, $p->finalUrl, $p->toContext())->hasWarning(Warning::BodyTruncated));
    }

    public function testCase76StatusAndNetworkErrors(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, new TransportResponse(404, [], ''));
        try {
            $this->fetcher()->fetch(self::PAGE);
            self::fail('expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(404, $e->status);
        }
        $t = (new ScriptedTransport())->on(self::ROBOTS, new TransportResponse(404, [], ''))->on(self::PAGE, new TransportResponse(500, [], ''));
        try {
            (new HttpFetcher($this->options(), $t))->fetch(self::PAGE);
            self::fail('expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(500, $e->status);
        }
        $this->expectException(NetworkException::class);
        $this->fetcher($this->options(['www.daily.example' => null]))->fetch(self::PAGE);
    }

    public function testCase77NoCookiesAndAuthorizationOnlyWhenSet(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, ['set-cookie' => 'a=1'], ''));
        $this->transport->on(self::PAGE, $this->page('', ['set-cookie' => 'session=abc']));
        $f = $this->fetcher();
        $f->fetch(self::PAGE);
        $this->clock->advance(11);
        $f->fetch(self::PAGE);
        foreach ($this->transport->requests as $r) {
            self::assertArrayNotHasKey('Cookie', $r->headers);
            self::assertArrayNotHasKey('Authorization', $r->headers);
            self::assertSame(self::UA, $r->headers['User-Agent']);
        }
        $t = (new ScriptedTransport())->on(self::ROBOTS, new TransportResponse(404, [], ''))->on(self::PAGE, $this->page());
        (new HttpFetcher($this->options()->withAuthorization('Bearer licensed-feed'), $t))->fetch(self::PAGE);
        self::assertSame('Bearer licensed-feed', $t->requests[1]->headers['Authorization']);
    }

    public function testCase78ToContextPassesCharsetAndRobots(): void
    {
        $html = "<html><head><title>Caf\xE9</title></head><body><article>" . str_repeat("<p>Caf\xE9 owners say the new rule is fair and workable for everyone in town.</p>", 8) . '</article></body></html>';
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, new TransportResponse(200, ['content-type' => 'text/html; charset=windows-1252', 'x-robots-tag' => 'noimageai'], $html));
        $p = $this->fetcher()->fetch(self::PAGE);
        self::assertSame('windows-1252', $p->charset);
        $ctx = $p->toContext();
        self::assertNotNull($ctx->robots);
        self::assertTrue($ctx->robots->refusesImages());
        $a = (new Extractor())->extract($p->body, $p->finalUrl, $ctx);
        self::assertStringContainsString("Caf\u{00E9} owners", $a->bodyText);
    }

    public function testLoggerSeesEventsWithoutBodies(): void
    {
        $log = [];
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, $this->page());
        $this->fetcher(null, null, null, $log)->fetch(self::PAGE);
        $messages = array_map(static fn (array $l): string => $l[1], $log);
        self::assertContains('robots.txt read', $messages);
        self::assertContains('response', $messages);
        foreach ($log as $l) {
            self::assertArrayNotHasKey('body', $l[2]);
        }
    }

    public function testFromUrlFetchesThenExtracts(): void
    {
        $this->transport->on(self::ROBOTS, new TransportResponse(404, [], ''));
        $this->transport->on(self::PAGE, $this->page());
        $a = ReadableExtract::fromUrl(self::PAGE, $this->options(), null, $this->transport);
        self::assertTrue($a->isReadable());
        self::assertSame(self::PAGE, $a->canonicalUrl);
    }
}
