<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Fetch;

use IndianOpinion\ReadableExtract\Exception\NetworkException;

/**
 * Resolver backed by the system's DNS (A and AAAA records).
 */
final class SystemResolver implements Resolver
{
    public function resolve(string $host): array
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }
        $error = null;
        set_error_handler(static function (int $no, string $msg) use (&$error): bool {
            $error = $msg;
            return true;
        });
        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } finally {
            restore_error_handler();
        }
        $ips = [];
        if (is_array($records)) {
            foreach ($records as $r) {
                if (isset($r['ip']) && is_string($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (isset($r['ipv6']) && is_string($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $v4 = gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }
        if ($ips === []) {
            throw new NetworkException('DNS lookup failed for ' . $host . ($error !== null ? ': ' . $error : ''));
        }
        return array_values(array_unique($ips));
    }
}
