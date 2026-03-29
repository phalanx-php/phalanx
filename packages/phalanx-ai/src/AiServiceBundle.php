<?php

declare(strict_types=1);

namespace Phalanx\Ai;

use Phalanx\Ai\Provider\ProviderConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AiServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(ProviderConfig::class)
            ->factory(static function () use ($context) {
                $config = ProviderConfig::create();

                $anthropicKey = $context['ANTHROPIC_API_KEY'] ?? null;
                if ($anthropicKey !== null) {
                    $config->anthropic(apiKey: $anthropicKey);
                }

                $openaiKey = $context['OPENAI_API_KEY'] ?? null;
                if ($openaiKey !== null) {
                    $config->openai(apiKey: $openaiKey);
                }

                $ollamaUrl = $context['OLLAMA_BASE_URL'] ?? null;
                if ($ollamaUrl !== null) {
                    $config->ollama(baseUrl: $ollamaUrl);
                }

                return $config;
            });
    }
}
