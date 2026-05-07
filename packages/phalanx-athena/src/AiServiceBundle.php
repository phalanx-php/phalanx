<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Athena\Swarm\Daemon8SwarmBus;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\Iris;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AiServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        Iris::services()->services($services, $context);

        $services->singleton(ProviderConfig::class)
            ->factory(static function (HttpClient $client) use ($context) {
                $config = ProviderConfig::create($client);

                if ($anthropicKey = $context['ANTHROPIC_API_KEY'] ?? null) {
                    $config->anthropic(apiKey: $anthropicKey);
                }

                if ($openaiKey = $context['OPENAI_API_KEY'] ?? null) {
                    $openaiBaseUrl = $context['OPENAI_BASE_URL'] ?? null;

                    if ($openaiBaseUrl === null) {
                        $config->openai(apiKey: $openaiKey);
                    } else {
                        $config->openai(apiKey: $openaiKey, baseUrl: $openaiBaseUrl);
                    }
                }

                if ($geminiKey = $context['GEMINI_API_KEY'] ?? null) {
                    $geminiModel = $context['GEMINI_MODEL'] ?? null;

                    if ($geminiModel === null) {
                        $config->gemini(apiKey: $geminiKey);
                    } else {
                        $config->gemini(apiKey: $geminiKey, model: $geminiModel);
                    }
                }

                $ollamaEnabled = ($context['OLLAMA_ENABLED'] ?? false) === true
                    || array_key_exists('OLLAMA_MODEL', $context)
                    || array_key_exists('OLLAMA_BASE_URL', $context);

                if ($ollamaEnabled) {
                    $config->ollama(
                        model: (string) ($context['OLLAMA_MODEL'] ?? 'llama3'),
                        baseUrl: (string) ($context['OLLAMA_BASE_URL'] ?? 'http://localhost:11434'),
                    );
                }

                return $config;
            });

        $services->singleton(SwarmConfig::class)
            ->factory(static function () use ($context) {
                $defaults = new SwarmConfig();

                return new SwarmConfig(
                    workspace: $context['SWARM_WORKSPACE'] ?? $defaults->workspace,
                    session: $context['SWARM_SESSION'] ?? $defaults->session,
                    daemon8Url: $context['DAEMON8_URL'] ?? $defaults->daemon8Url,
                    app: $context['DAEMON8_APP'] ?? $defaults->app,
                );
            });

        $services->singleton(Daemon8SwarmBus::class)
            ->needs(SwarmConfig::class, HttpClient::class)
            ->factory(static fn(SwarmConfig $config, HttpClient $client) => new Daemon8SwarmBus($config, $client));

        $services->alias(SwarmBus::class, Daemon8SwarmBus::class);
    }
}
