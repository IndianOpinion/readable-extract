<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Exception\ConfigurationException;

/**
 * Fetch settings. The user agent is required and must name your product
 * and a contact URL or address; a browser-like user agent is refused.
 * Immutable: every with*() returns a changed copy.
 */
final class FetchOptions
{
    private string $userAgent;
    private string $productToken;
    private float $hostInterval = 10.0;
    private float $maxCrawlDelay = 60.0;
    private float $waitBudget = 0.0;
    private float $defaultBackoff = 600.0;
    private float $robotsTtl = 86400.0;
    private float $connectTimeout = 5.0;
    private float $totalTimeout = 20.0;
    private int $bodyCap = 5242880;
    private int $maxRedirects = 5;
    /** @var list<int> */
    private array $allowedPorts = [80, 443];
    private bool $allowPrivateAddresses = false;
    /** @var list<string> */
    private array $honourRobotsMeta = RobotsDirectives::DEFAULT_HONOURED;
    private ?string $authorization = null;
    private ?Resolver $resolver = null;
    private ?Clock $clock = null;

    public function __construct(string $userAgent, ?string $robotsProductToken = null)
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            throw new ConfigurationException('A user agent naming your product and a contact is required');
        }
        if (stripos($ua, 'mozilla/') === 0) {
            throw new ConfigurationException('A browser-like user agent (Mozilla/...) is not allowed');
        }
        if (preg_match('#https?://|mailto:|@#i', $ua) !== 1) {
            throw new ConfigurationException('The user agent must include a contact URL or email address');
        }
        if (preg_match('/[\r\n]/', $ua) === 1 || strlen($ua) > 512) {
            throw new ConfigurationException('The user agent must be one line under 512 bytes');
        }
        $token = $robotsProductToken ?? (preg_split('#[/\s]#', $ua)[0] ?? '');
        if (preg_match('/^[A-Za-z_\-]+$/', $token) !== 1) {
            throw new ConfigurationException('The robots product token must be letters, "_" and "-" only: ' . $token);
        }
        $this->userAgent = $ua;
        $this->productToken = strtolower($token);
    }

    public function withHostInterval(float $seconds): self
    {
        return $this->with('hostInterval', $this->nonNegative($seconds, 'hostInterval'));
    }

    public function withMaxCrawlDelay(float $seconds): self
    {
        return $this->with('maxCrawlDelay', $this->nonNegative($seconds, 'maxCrawlDelay'));
    }

    public function withWaitBudget(float $seconds): self
    {
        return $this->with('waitBudget', $this->nonNegative($seconds, 'waitBudget'));
    }

    public function withDefaultBackoff(float $seconds): self
    {
        return $this->with('defaultBackoff', $this->nonNegative($seconds, 'defaultBackoff'));
    }

    public function withRobotsTtl(float $seconds): self
    {
        return $this->with('robotsTtl', $this->nonNegative($seconds, 'robotsTtl'));
    }

    public function withTimeouts(float $connect, float $total): self
    {
        if ($connect <= 0 || $total <= 0 || $connect > $total) {
            throw new ConfigurationException('Timeouts must be positive and connect must not exceed total');
        }
        return $this->with('connectTimeout', $connect)->with('totalTimeout', $total);
    }

    public function withBodyCap(int $bytes): self
    {
        if ($bytes < 1024) {
            throw new ConfigurationException('bodyCap must be at least 1024 bytes');
        }
        return $this->with('bodyCap', $bytes);
    }

    public function withMaxRedirects(int $hops): self
    {
        if ($hops < 0 || $hops > 10) {
            throw new ConfigurationException('maxRedirects must be between 0 and 10');
        }
        return $this->with('maxRedirects', $hops);
    }

    /** @param list<int> $ports */
    public function withAllowedPorts(array $ports): self
    {
        foreach ($ports as $p) {
            if (!is_int($p) || $p < 1 || $p > 65535) {
                throw new ConfigurationException('Ports must be integers from 1 to 65535');
            }
        }
        return $this->with('allowedPorts', array_values(array_unique($ports)));
    }

    /** For tests only: allows loopback and private addresses. */
    public function withAllowPrivateAddresses(bool $allow): self
    {
        return $this->with('allowPrivateAddresses', $allow);
    }

    /**
     * Robots meta and header directives that refuse content. You may add
     * directives (for example noindex); none and noarchive cannot be removed.
     *
     * @param list<string> $directives
     */
    public function withHonourRobotsMeta(array $directives): self
    {
        $d = array_map(static fn (string $x): string => strtolower(trim($x)), $directives);
        foreach (RobotsDirectives::REQUIRED_HONOURED as $required) {
            if (!in_array($required, $d, true)) {
                throw new ConfigurationException('"' . $required . '" cannot be removed from honourRobotsMeta');
            }
        }
        return $this->with('honourRobotsMeta', array_values(array_unique($d)));
    }

    /** An Authorization header for a source you are licensed to fetch. Sent only when set. */
    public function withAuthorization(string $value): self
    {
        if (preg_match('/[\r\n]/', $value) === 1 || trim($value) === '') {
            throw new ConfigurationException('Authorization must be one non-empty line');
        }
        return $this->with('authorization', $value);
    }

    public function withResolver(Resolver $resolver): self
    {
        return $this->with('resolver', $resolver);
    }

    public function withClock(Clock $clock): self
    {
        return $this->with('clock', $clock);
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function productToken(): string
    {
        return $this->productToken;
    }

    public function hostInterval(): float
    {
        return $this->hostInterval;
    }

    public function maxCrawlDelay(): float
    {
        return $this->maxCrawlDelay;
    }

    public function waitBudget(): float
    {
        return $this->waitBudget;
    }

    public function defaultBackoff(): float
    {
        return $this->defaultBackoff;
    }

    public function robotsTtl(): float
    {
        return $this->robotsTtl;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function totalTimeout(): float
    {
        return $this->totalTimeout;
    }

    public function bodyCap(): int
    {
        return $this->bodyCap;
    }

    public function maxRedirects(): int
    {
        return $this->maxRedirects;
    }

    /** @return list<int> */
    public function allowedPorts(): array
    {
        return $this->allowedPorts;
    }

    public function allowPrivateAddresses(): bool
    {
        return $this->allowPrivateAddresses;
    }

    /** @return list<string> */
    public function honourRobotsMeta(): array
    {
        return $this->honourRobotsMeta;
    }

    public function authorization(): ?string
    {
        return $this->authorization;
    }

    public function resolver(): Resolver
    {
        return $this->resolver ?? new SystemResolver();
    }

    public function clock(): Clock
    {
        return $this->clock ?? new SystemClock();
    }

    private function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;
        return $copy;
    }

    private function nonNegative(float $v, string $name): float
    {
        if ($v < 0 || !is_finite($v)) {
            throw new ConfigurationException($name . ' must be a finite number of seconds, zero or more');
        }
        return $v;
    }
}
