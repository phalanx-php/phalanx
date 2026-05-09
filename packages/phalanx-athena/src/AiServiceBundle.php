<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Athena\Swarm\Daemon8SwarmBus;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Optional;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\Iris;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class AiServiceBundle extends ServiceBundle
{
    /**
     * AI providers are pluggable — at least one provider key should be set,
     * but the bundle boots regardless and treats absent keys as "provider
     * unavailable" feature flags rather than hard failures.
     */
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(
            Optional::env('ANTHROPIC_API_KEY', description: 'Anthropic Claude provider API key'),
            Optional::env('OPENAI_API_KEY', description: 'OpenAI provider API key'),
            Optional::env('GEMINI_API_KEY', description: 'Google Gemini provider API key'),
            Optional::env('OLLAMA_BASE_URL', fallback: 'http://localhost:11434', description: 'Ollama base URL'),
            Optional::env('OLLAMA_MODEL', fallback: 'llama3', description: 'Ollama default model'),
        );
    }


    public function services(Services $services, AppContext $context): void
    {
        Iris::services()->services($services, $context);

        $services->singleton(ProviderConfig::class)
            ->factory(static function (HttpClient $client) use ($context) {
                $config = ProviderConfig::create($client);

                if ($anthropicKey = $context->get('ANTHROPIC_API_KEY')) {
                    $config->anthropic(apiKey: $anthropicKey);
                }

                if ($openaiKey = $context->get('OPENAI_API_KEY')) {
                    $openaiBaseUrl = $context->get('OPENAI_BASE_URL');

                    if ($openaiBaseUrl === null) {
                        $config->openai(apiKey: $openaiKey);
                    } else {
                        $config->openai(apiKey: $openaiKey, baseUrl: $openaiBaseUrl);
                    }
                }

                if ($geminiKey = $context->get('GEMINI_API_KEY')) {
                    $geminiModel = $context->get('GEMINI_MODEL');

                    if ($geminiModel === null) {
                        $config->gemini(apiKey: $geminiKey);
                    } else {
                        $config->gemini(apiKey: $geminiKey, model: $geminiModel);
                    }
                }

                $ollamaEnabled = $context->bool('OLLAMA_ENABLED', false)
                    || $context->has('OLLAMA_MODEL')
                    || $context->has('OLLAMA_BASE_URL');

                if ($ollamaEnabled) {
                    $config->ollama(
                        model: $context->string('OLLAMA_MODEL', 'llama3'),
                        baseUrl: $context->string('OLLAMA_BASE_URL', 'http://localhost:11434'),
                    );
                }

                return $config;
            });

        $services->singleton(SwarmConfig::class)
            ->factory(static function () use ($context) {
                $defaults = new SwarmConfig();

                return new SwarmConfig(
                    workspace: $context->get('SWARM_WORKSPACE', $defaults->workspace),
                    session: $context->get('SWARM_SESSION', $defaults->session),
                    daemon8Url: $context->get('DAEMON8_URL', $defaults->daemon8Url),
                    app: $context->get('DAEMON8_APP', $defaults->app),
                );
            });

        $services->singleton(Daemon8SwarmBus::class)
            ->needs(SwarmConfig::class, HttpClient::class)
            ->factory(static fn(SwarmConfig $config, HttpClient $client) => new Daemon8SwarmBus($config, $client));

        $services->alias(SwarmBus::class, Daemon8SwarmBus::class);
    }
}
