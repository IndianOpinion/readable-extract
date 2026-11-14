<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

final class TransportResponse
{
    /** @var array<string, list<string>> header names lowercased */
    public readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
        public readonly bool $truncated = false,
    ) {
        $norm = [];
        foreach ($headers as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $v) {
                $norm[strtolower((string) $name)][] = (string) $v;
            }
        }
        $this->headers = $norm;
    }

    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)] ?? [];
        return $v === [] ? null : $v[0];
    }
}
