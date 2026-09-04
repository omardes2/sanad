<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\AiProvider;
use App\Exceptions\Ai\AiConfigurationException;
use App\Providers\Ai\GroqChatProvider;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the active AiProvider by name from config('ai.provider'). Adding a
 * provider is a one-line case here plus a config entry and a provider class —
 * no other part of the app changes. Tests (and future packages) can register a
 * provider at runtime via extend(), proving the app is not coupled to Groq.
 */
class AiManager
{
    /** @var array<string, Closure(Container, array<string, mixed>): AiProvider> */
    private array $custom = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register or override a provider factory at runtime.
     *
     * @param  Closure(Container, array<string, mixed>): AiProvider  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->custom[$name] = $factory;
    }

    public function provider(?string $name = null): AiProvider
    {
        $name ??= (string) config('ai.provider', 'groq');

        /** @var array<string, mixed> $config */
        $config = (array) config("ai.providers.{$name}", []);

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($this->container, $config);
        }

        return match ($name) {
            'groq' => new GroqChatProvider($name, $config),
            // 'gemini' => new GeminiChatProvider($name, $config),
            // 'ollama' => new OllamaChatProvider($name, $config),
            default => throw AiConfigurationException::unknownProvider($name),
        };
    }
}
