<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

final class ProviderConfig
{
    /** @var array<string, LlmProvider> */
    private array $providers = [];

    private ?string $defaultProvider = null;
    private Strategy $strategy = Strategy::Fallback;

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function anthropic(
        string $apiKey,
        string $model = 'claude-sonnet-4-20250514',
        string $baseUrl = 'https://api.anthropic.com',
    ): self {
        $this->providers['anthropic'] = new AnthropicProvider(new AnthropicConfig(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
        ));
        $this->defaultProvider ??= 'anthropic';
        return $this;
    }

    public function openai(
        string $apiKey,
        string $model = 'gpt-4o',
        string $baseUrl = 'https://api.openai.com',
    ): self {
        $this->providers['openai'] = new OpenAiProvider(new OpenAiConfig(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
        ));
        $this->defaultProvider ??= 'openai';
        return $this;
    }

    public function gemini(
        string $apiKey,
        string $model = 'gemini-1.5-flash',
        string $baseUrl = 'https://generativelanguage.googleapis.com',
    ): self {
        $this->providers['gemini'] = new GeminiProvider(new GeminiConfig(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
        ));
        $this->defaultProvider ??= 'gemini';
        return $this;
    }

    public function ollama(string $model = 'llama3', string $baseUrl = 'http://localhost:11434'): self
    {
        $this->providers['ollama'] = new OllamaProvider(new OllamaConfig(
            model: $model,
            baseUrl: $baseUrl,
        ));
        $this->defaultProvider ??= 'ollama';
        return $this;
    }

    public function strategy(Strategy $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    public function resolve(?string $name = null): LlmProvider
    {
        if ($name === null && count($this->providers) > 1) {
            return match ($this->strategy) {
                Strategy::Race => ProviderStrategy::primary(...array_values($this->providers)),
                Strategy::Fallback => ProviderStrategy::fallback(...array_values($this->providers)),
                Strategy::RoundRobin => ProviderStrategy::roundRobin(...array_values($this->providers)),
                Strategy::Cheapest => ProviderStrategy::fallback(...array_values($this->providers)),
            };
        }

        $key = $name ?? $this->defaultProvider;

        if ($key === null || !isset($this->providers[$key])) {
            throw new \RuntimeException(
                $key === null
                    ? 'No providers configured'
                    : "Provider '{$key}' not configured",
            );
        }

        return $this->providers[$key];
    }

    /** @return array<string, LlmProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
