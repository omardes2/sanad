<?php

declare(strict_types=1);

namespace App\Contracts\Credentials;

/**
 * DNS lookup abstraction so the SSRF re-validation (Phase C3) can be tested
 * without the network. Returns every A/AAAA address of a host.
 */
interface HostResolver
{
    /**
     * @return list<string> IP addresses; empty when the host does not resolve
     */
    public function resolve(string $host): array;
}
