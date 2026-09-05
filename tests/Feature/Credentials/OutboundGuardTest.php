<?php

declare(strict_types=1);

use App\Contracts\Credentials\HostResolver;
use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Enums\HealthCheckKind;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\OutboundBlockedException;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\AiManager;
use App\Services\Ai\Health\ProviderHealthService;
use App\Support\Rbac\Role;
use App\Support\Security\OutboundGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function c3Resolver(array $map): HostResolver
{
    return new class($map) implements HostResolver
    {
        public function __construct(private array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    };
}

it('pins a candidate URL to its verified public address, forbids redirects, and blocks private / mixed / unresolvable answers', function () {
    $guard = new OutboundGuard(c3Resolver(['proxy.example.com' => ['93.184.216.34']]));
    $pinned = $guard->pin('https://proxy.example.com:8443/v1/');

    expect($pinned->url)->toBe('https://proxy.example.com:8443/v1')
        ->and($pinned->ip)->toBe('93.184.216.34')
        ->and($pinned->httpOptions()['allow_redirects'])->toBeFalse()
        ->and($pinned->httpOptions()['curl'][CURLOPT_RESOLVE])->toBe(['proxy.example.com:8443:93.184.216.34']);

    // DNS rebinding: the name now (also) resolves to a private address.
    expect(fn () => (new OutboundGuard(c3Resolver(['proxy.example.com' => ['10.0.0.9']])))->pin('https://proxy.example.com/v1'))->toThrow(OutboundBlockedException::class)
        ->and(fn () => (new OutboundGuard(c3Resolver(['proxy.example.com' => ['93.184.216.34', '169.254.169.254']])))->pin('https://proxy.example.com/v1'))->toThrow(OutboundBlockedException::class)
        ->and(fn () => (new OutboundGuard(c3Resolver([])))->pin('https://proxy.example.com/v1'))->toThrow(OutboundBlockedException::class)
        ->and(fn () => (new OutboundGuard(c3Resolver(['proxy.example.com' => ['93.184.216.34']])))->pin('http://proxy.example.com/v1'))->toThrow(OutboundBlockedException::class)
        ->and(fn () => (new OutboundGuard(c3Resolver([])))->pin('https://127.0.0.1/v1'))->toThrow(OutboundBlockedException::class);
});

it('Test Connection with the stored base_url re-validates at call time: private DNS → refused with no row; public → pinned request to the candidate, runtime untouched', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $fx = c3Catalog();
    $fx['groq']->update(['base_url' => 'https://proxy.example.com/v1']);
    Http::fake(['proxy.example.com/*' => Http::response(['data' => []], 200), 'api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

    app()->instance(HostResolver::class, c3Resolver(['proxy.example.com' => ['192.168.1.20']]));
    expect(fn () => app(ProviderHealthService::class)->run($fx['groq']->fresh(), HealthCheckKind::Auth, candidateBaseUrl: true))->toThrow(OutboundBlockedException::class)
        ->and(ProviderHealthCheck::count())->toBe(0);
    Http::assertNothingSent();

    app()->instance(HostResolver::class, c3Resolver(['proxy.example.com' => ['93.184.216.34']]));
    $check = app(ProviderHealthService::class)->run($fx['groq']->fresh(), HealthCheckKind::Auth, candidateBaseUrl: true);

    expect($check->candidate_base_url)->toBeTrue()->and($check->status->value)->toBe('ok');
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://proxy.example.com/v1/models'));

    // The runtime (routing + chat) still uses the config endpoint — the DB base_url is stored-only in C3.
    app(AiManager::class)->provider('groq')->chat(new AiRequest(messages: [AiMessage::user('x')], temperature: 0.1, maxOutputTokens: 5, timeout: 5));
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.groq.com/openai/v1/chat/completions'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'proxy.example.com/v1/chat'));

    // No stored base_url → nothing to test.
    $fx['openai']->update(['base_url' => null]);
    expect(fn () => app(ProviderHealthService::class)->run($fx['openai']->fresh(), HealthCheckKind::Connectivity, candidateBaseUrl: true))->toThrow(CredentialLifecycleException::class);
});
