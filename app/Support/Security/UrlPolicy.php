<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * SSRF policy for any admin-supplied outbound URL (decision B of Phase C):
 * https only, a real host, no loopback / private / link-local / metadata
 * addresses, no IP literals in blocked ranges, and — when a resolver is
 * supplied — every address the host resolves to is checked as well. Phase C2
 * applies it on save; Phase C3 re-applies it (with DNS) on every outbound
 * call, so DNS rebinding after save is caught at use time.
 *
 * A private-network provider is a Super Admin override in a later phase; the
 * default policy here has no exceptions.
 */
final class UrlPolicy
{
    /** @var list<string> */
    private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal', 'metadata', 'instance-data'];

    /** @var list<string> */
    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain'];

    /**
     * @param  null|callable(string): list<string>  $resolver  host => resolved IPs
     * @return list<string> human-readable errors; empty = allowed
     */
    public static function check(string $url, ?callable $resolver = null): array
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($url === '' || $parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return ['العنوان غير صالح: يجب أن يكون بصيغة https://host[:port][/path].'];
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return ['يُسمح بـhttps فقط.'];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['لا يُسمح ببيانات اعتماد داخل العنوان.'];
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || in_array($host, self::BLOCKED_HOSTS, true)) {
            return ["المضيف [{$host}] غير مسموح."];
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return ["المضيف [{$host}] غير مسموح (نطاق داخلي)."];
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? [] : ["العنوان [{$host}] يقع في نطاق شبكة خاصة أو محلية أو metadata."];
        }

        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1 || ! str_contains($host, '.')) {
            return ["المضيف [{$host}] ليس اسم نطاق عامًا صالحًا."];
        }

        if ($resolver !== null) {
            foreach ($resolver($host) as $ip) {
                if (! self::isPublicIp((string) $ip)) {
                    return ["المضيف [{$host}] يُحلّ إلى عنوان غير مسموح."];
                }
            }
        }

        return [];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Rejects private (10/8, 172.16/12, 192.168/16, fc00::/7) and reserved
        // (0/8, 127/8, 169.254/16, ::1, fe80::/10, 100.64/10 is NOT covered) ranges.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT and the IPv4-mapped / documentation ranges.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['192.0.2.0', 24], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 3]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);

                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d) inherits the IPv4 verdict.
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return self::isPublicIp(substr($ip, 7));
        }

        return true;
    }
}
