<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Support;

use IndianOpinion\ReadableExtract\Exception\NetworkException;
use IndianOpinion\ReadableExtract\Fetch\Transport;
use IndianOpinion\ReadableExtract\Fetch\TransportRequest;
use IndianOpinion\ReadableExtract\Fetch\TransportResponse;

/**
 * A transport that answers from a script and records every request.
 */
final class ScriptedTransport implements Transport
{
    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @var array<string, list<TransportResponse|\Throwable>> */
    private array $script = [];

    public function on(string $url, TransportResponse|\Throwable ...$responses): self
    {
        foreach ($responses as $r) {
            $this->script[$url][] = $r;
        }
        return $this;
    }

    public static function html(string $body = '<html><body><p>Hello</p></body></html>', int $status = 200, array $headers = []): TransportResponse
    {
        return new TransportResponse($status, $headers + ['content-type' => 'text/html; charset=utf-8'], $body);
    }

    public static function robots(string $body): TransportResponse
    {
        return new TransportResponse(200, ['content-type' => 'text/plain'], $body);
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;
        $queue = $this->script[$request->url] ?? [];
        if ($queue === []) {
            throw new NetworkException('No scripted response for ' . $request->url);
        }
        $r = count($queue) > 1 ? array_shift($this->script[$request->url]) : $queue[0];
        if ($r instanceof \Throwable) {
            throw $r;
        }
        $body = $r->body;
        $truncated = $r->truncated;
        if (strlen($body) > $request->bodyCap) {
            $body = substr($body, 0, $request->bodyCap);
            $truncated = true;
        }
        return new TransportResponse($r->status, $r->headers, $body, $truncated);
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (TransportRequest $r): string => $r->url, $this->requests);
    }
}
