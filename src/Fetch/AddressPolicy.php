<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

/**
 * Case 74: addresses the fetcher never connects to.
 */
final class AddressPolicy
{
    /** @var list<array{0: string, 1: int}> */
    private const V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** @var list<array{0: string, 1: int}> */
    private const V6 = [
        ['::', 96], ['::1', 128], ['100::', 64], ['2001::', 23], ['2001:db8::', 32], ['2002::', 16],
        ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8],
    ];

    /** True when the address is private, loopback, link-local, unique-local, multicast, reserved or metadata. */
    public static function isBlocked(string $ip): bool
    {
        $ip = trim($ip, '[]');
        $bin = inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 4) {
            return self::inAny($bin, self::V4);
        }
        // IPv4-mapped (::ffff:0:0/96) and NAT64 (64:ff9b::/96): check the embedded v4.
        $prefix12 = substr($bin, 0, 12);
        if ($prefix12 === str_repeat("\0", 10) . "\xff\xff" || $prefix12 === "\0\x64\xff\x9b" . str_repeat("\0", 8)) {
            return self::inAny(substr($bin, 12), self::V4);
        }
        return self::inAny($bin, self::V6);
    }

    /** @param list<array{0: string, 1: int}> $nets */
    private static function inAny(string $bin, array $nets): bool
    {
        foreach ($nets as [$net, $bits]) {
            $n = inet_pton($net);
            if ($n === false || strlen($n) !== strlen($bin)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($n, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($n[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
