<?php

declare(strict_types=1);

namespace App\Data\Credentials;

/**
 * A validated candidate endpoint and the HTTP client options that pin the
 * connection to the verified public address and forbid redirects.
 */
final readonly class PinnedEndpoint
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /**
     * @return array<string, mixed> Guzzle request options
     */
    public function httpOptions(): array
    {
        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => ["{$this->host}:{$this->port}:{$this->ip}"],
            ],
        ];
    }
}
