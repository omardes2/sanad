<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Contracts\Credentials\HostResolver;
use App\Data\Credentials\PinnedEndpoint;
use App\Exceptions\Credentials\OutboundBlockedException;

/**
 * Call-time SSRF re-validation for a CANDIDATE URL (Phase C3, decision B):
 * the policy is applied again right before the request, the host is resolved
 * (A/AAAA) and every address must be public, and the request is PINNED to the
 * verified address (curl CURLOPT_RESOLVE) with redirects disabled — so a DNS
 * answer that changes between validation and connection (rebinding) cannot
 * reach a private address, and a redirect cannot either.
 *
 * Config/env base URLs are NOT routed through this guard in C3 (their
 * behaviour is unchanged); only database URLs exercised by Test Connection are.
 */
final class OutboundGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @throws OutboundBlockedException
     */
    public function pin(string $url): PinnedEndpoint
    {
        $errors = UrlPolicy::check($url, fn (string $host): array => $this->resolver->resolve($host));

        if ($errors !== []) {
            throw OutboundBlockedException::because($errors);
        }

        $parts = parse_url(trim($url));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? 443);
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);
        $public = array_values(array_filter($ips, static fn (string $ip): bool => UrlPolicy::isPublicIp($ip)));

        if ($public === [] || count($public) !== count($ips)) {
            throw OutboundBlockedException::because(["المضيف [{$host}] لا يُحلّ إلى عنوان عام."]);
        }

        return new PinnedEndpoint(
            url: rtrim(trim($url), '/'),
            host: $host,
            port: $port,
            ip: $public[0],
        );
    }
}
