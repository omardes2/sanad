<?php

declare(strict_types=1);

use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Exceptions\Ai\AiConfigurationException;
use App\Providers\Ai\GroqChatProvider;
use App\Services\Ai\AiManager;

it('resolves the configured provider by name', function () {
    aiConfigure();

    $provider = app(AiManager::class)->provider();

    expect($provider)->toBeInstanceOf(GroqChatProvider::class)
        ->and($provider->name())->toBe('groq');
});

it('throws for an unknown provider', function () {
    config(['ai.provider' => 'does-not-exist']);

    expect(fn () => app(AiManager::class)->provider())->toThrow(AiConfigurationException::class);
});

it('lets a new provider be registered without changing app code (no Groq coupling)', function () {
    $fake = new class implements AiProvider
    {
        public function chat(AiRequest $request): AiResponse
        {
            return new AiResponse('fake reply');
        }

        public function name(): string
        {
            return 'fake';
        }
    };

    $manager = app(AiManager::class);
    $manager->extend('fake', fn () => $fake);

    expect($manager->provider('fake'))->toBe($fake)
        ->and($manager->provider('fake')->chat(new AiRequest([], 0.5, 100, 10))->text)->toBe('fake reply');
});
