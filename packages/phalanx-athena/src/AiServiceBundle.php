<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Athena\Swarm\Daemon8SwarmBus;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AiServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(ProviderConfig::class)
            ->factory(static function () use ($context) {
                $config = ProviderConfig::create();

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

                if ($ollamaUrl = $context['OLLAMA_BASE_URL'] ?? null) {
                    $config->ollama(baseUrl: $ollamaUrl);
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
            ->needs(SwarmConfig::class)
            ->factory(static fn(SwarmConfig $config) => new Daemon8SwarmBus($config));

        $services->alias(SwarmBus::class, Daemon8SwarmBus::class);
    }
}
