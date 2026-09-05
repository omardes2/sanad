<?php

declare(strict_types=1);

use App\Contracts\Ai\SupportsChat;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Enums\AiOperation;
use App\Exceptions\Ai\AiConfigurationException;
use App\Providers\Ai\GroqChatProvider;
use App\Providers\Ai\OpenAIProvider;
use App\Services\Ai\AiManager;

it('resolves the configured provider by name', function () {
    aiConfigure();

    $provider = app(AiManager::class)->provider();

    expect($provider)->toBeInstanceOf(GroqChatProvider::class)
        ->and($provider->name())->toBe('groq');
});

it('resolves the OpenAI provider', function () {
    $provider = app(AiManager::class)->provider('openai');

    expect($provider)->toBeInstanceOf(OpenAIProvider::class)
        ->and($provider->name())->toBe('openai')
        ->and($provider->supports(AiOperation::Chat))->toBeTrue()
        ->and($provider->supports(AiOperation::Vision))->toBeFalse();
});

it('throws for an unknown provider', function () {
    config(['ai.provider' => 'does-not-exist']);

    expect(fn () => app(AiManager::class)->provider())->toThrow(AiConfigurationException::class);
});

it('knows built-in and registered provider keys', function () {
    $manager = app(AiManager::class);

    expect($manager->has('openai'))->toBeTrue()
        ->and($manager->has('groq'))->toBeTrue()
        ->and($manager->has('nope'))->toBeFalse();
});

it('lets a new provider be registered without changing app code (no vendor coupling)', function () {
    $fake = new class implements SupportsChat
    {
        public function chat(AiRequest $request): AiResponse
        {
            return new AiResponse('fake reply');
        }

        public function name(): string
        {
            return 'fake';
        }

        public function supports(AiOperation $operation): bool
        {
            return $operation === AiOperation::Chat;
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    $manager = app(AiManager::class);
    $manager->extend('fake', fn () => $fake);

    expect($manager->has('fake'))->toBeTrue()
        ->and($manager->provider('fake'))->toBe($fake)
        ->and($manager->provider('fake')->chat(new AiRequest([], 0.5, 100, 10))->text)->toBe('fake reply');
});
