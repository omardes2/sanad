<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Contracts\Credentials\HostResolver;

final class DnsHostResolver implements HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);

            foreach (is_array($records) ? $records : [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            $ips = is_array($fallback) ? array_values($fallback) : [];
        }

        return array_values(array_unique($ips));
    }
}
