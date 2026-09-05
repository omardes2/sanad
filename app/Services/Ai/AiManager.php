<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\AiProvider;
use App\Exceptions\Ai\AiConfigurationException;
use App\Providers\Ai\GroqChatProvider;
use App\Providers\Ai\OpenAIProvider;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * The provider registry: resolves an AiProvider by key from config('ai.providers').
 * Adding a provider is a one-line case here plus a config entry and a provider
 * class — nothing else in the app changes. Tests (and future packages) can
 * register a provider at runtime via extend(), proving the app is coupled to no
 * vendor. Which provider actually serves a request is the router's decision.
 */
class AiManager
{
    /** @var list<string> */
    private const BUILT_IN = ['openai', 'groq'];

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

    /**
     * Whether a provider key is known (built-in or registered via extend()).
     */
    public function has(string $name): bool
    {
        return isset($this->custom[$name]) || in_array($name, self::BUILT_IN, true);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_keys($this->custom), ...self::BUILT_IN]));
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
            'openai' => new OpenAIProvider($name, $config),
            'groq' => new GroqChatProvider($name, $config),
            // 'gemini' => new GeminiChatProvider($name, $config),
            // 'ollama' => new OllamaChatProvider($name, $config),
            default => throw AiConfigurationException::unknownProvider($name),
        };
    }
}
