<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Exception\ConfigurationException;
use IndianOpinion\ReadableExtract\Exception\NetworkException;

/**
 * Default transport over ext-curl. One GET, no redirects, no cookie
 * engine, http and https only, the body read stops at the cap.
 */
final class CurlTransport implements Transport
{
    public function __construct()
    {
        if (!function_exists('curl_init')) {
            throw new ConfigurationException('CurlTransport needs ext-curl; pass another Transport');
        }
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $url = $request->url;
        if ($url === '') {
            throw new NetworkException('Empty URL');
        }
        $ch = curl_init();
        $headers = [];
        $body = '';
        $truncated = false;
        $cap = $request->bodyCap;
        $lines = [];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($request->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($request->totalTimeout * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $len = strlen($line);
                $line = trim($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (($colon = strpos($line, ':')) !== false) {
                    $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated, $cap): int {
                $room = $cap - strlen($body);
                if (strlen($chunk) > $room) {
                    $body .= substr($chunk, 0, max(0, $room));
                    $truncated = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false && !($truncated && $errno === CURLE_WRITE_ERROR)) {
            throw new NetworkException('Request failed (' . $errno . '): ' . $error);
        }
        return new TransportResponse($status, $headers, $body, $truncated);
    }
}
