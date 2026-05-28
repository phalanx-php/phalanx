<?php

declare(strict_types=1);

namespace Sentinel;

use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Sentinel\Render\ConsoleRenderer;

final class SentinelServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        // Override AiServiceBundle's ProviderConfig to honour ANTHROPIC_MODEL
        // from $context (Sentinel defaults to Haiku-class for cheap, fast reviews).
        $services->singleton(ProviderConfig::class)
            ->factory(static function () use ($context): ProviderConfig {
                $config = ProviderConfig::create();

                if ($anthropicKey = $context['ANTHROPIC_API_KEY'] ?? null) {
                    $model = $context['ANTHROPIC_MODEL'] ?? 'claude-haiku-4-5-20251001';
                    $config->anthropic(apiKey: $anthropicKey, model: $model);
                }

                if ($openaiKey = $context['OPENAI_API_KEY'] ?? null) {
                    $config->openai(apiKey: $openaiKey);
                }

                return $config;
            });

        $services->singleton(SentinelConfig::class)
            ->factory(static function () use ($context): SentinelConfig {
                $projectRoot = rtrim($context['SENTINEL_PROJECT_ROOT'] ?? getcwd(), '/');

                return new SentinelConfig(
                    projectRoot: $projectRoot,
                    dossierDir:  rtrim($context['SENTINEL_DOSSIER_DIR'] ?? dirname(__DIR__) . '/personas', '/'),
                    errorLog:    $context['SENTINEL_ERROR_LOG'] ?? $projectRoot . '/sentinel-error.log',
                    debounce:    (float) ($context['SENTINEL_DEBOUNCE'] ?? 0.5),
                );
            });

        $services->singleton(ConsoleRenderer::class)
            ->needs(SentinelConfig::class)
            ->factory(static fn(SentinelConfig $config): ConsoleRenderer => new ConsoleRenderer($config));
    }
}
