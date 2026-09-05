<?php

declare(strict_types=1);

use App\Support\Security\UrlPolicy;

it('allows only public https hosts', function (string $url) {
    expect(UrlPolicy::check($url))->toBe([]);
})->with([
    'https://api.openai.com/v1',
    'https://proxy.example.com:8443/openai/v1',
    'https://8.8.8.8/',
    'https://[2606:4700:4700::1111]/v1',
]);

it('rejects non-https, credentials, loopback, private, link-local, metadata, CGNAT, documentation and internal names', function (string $url) {
    expect(UrlPolicy::check($url))->not->toBe([]);
})->with([
    'http://api.openai.com/v1',
    'ftp://api.openai.com/',
    'https://user:secret@api.openai.com/',
    'https://localhost/',
    'https://LOCALHOST:8080/',
    'https://127.0.0.1/',
    'https://[::1]/',
    'https://0.0.0.0/',
    'https://10.1.2.3/',
    'https://172.16.5.5/',
    'https://192.168.1.1/',
    'https://169.254.169.254/latest/meta-data/',
    'https://metadata.google.internal/computeMetadata/v1/',
    'https://metadata/',
    'https://instance-data/',
    'https://100.64.0.1/',
    'https://192.0.0.1/',
    'https://198.18.0.1/',
    'https://192.0.2.1/',
    'https://203.0.113.9/',
    'https://224.0.0.1/',
    'https://[fd00::1]/',
    'https://[fe80::1]/',
    'https://[::ffff:10.0.0.1]/',
    'https://intranet.local/',
    'https://svc.internal/',
    'https://box.localdomain/',
    'https://foo.localhost/',
    'https://nodots/',
    'https://',
    'not a url',
    '',
]);

it('re-validates the resolved addresses when a resolver is supplied (DNS rebinding)', function () {
    $private = static fn (string $host): array => ['93.184.216.34', '10.0.0.7'];
    $public = static fn (string $host): array => ['93.184.216.34'];

    expect(UrlPolicy::check('https://api.example.com/v1', $private))->not->toBe([])
        ->and(UrlPolicy::check('https://api.example.com/v1', $public))->toBe([]);
});
