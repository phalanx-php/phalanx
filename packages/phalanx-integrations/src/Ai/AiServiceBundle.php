<?php

declare(strict_types=1);

namespace Phalanx\Integration\Ai;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AiServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $aiConfig = new AiConfig(
            claudeApiKey: $context['claude_api_key'] ?? '',
            claudeModel: $context['claude_model'] ?? 'claude-sonnet-4-5',
            openaiApiKey: $context['openai_api_key'] ?? '',
            openaiModel: $context['openai_model'] ?? 'gpt-4.1-mini',
            maxTokens: (int) ($context['ai_max_tokens'] ?? 1024),
        );

        $services->singleton(ClaudeClient::class)
            ->factory(static function () use ($aiConfig) {
                if ($aiConfig->claudeApiKey === '') {
                    throw new \RuntimeException('CLAUDE_API_KEY is required to use ClaudeClient');
                }
                return new ClaudeClient($aiConfig);
            });

        $services->singleton(GptClient::class)
            ->factory(static function () use ($aiConfig) {
                if ($aiConfig->openaiApiKey === '') {
                    throw new \RuntimeException('OPENAI_API_KEY is required to use GptClient');
                }
                return new GptClient($aiConfig);
            });
    }
}
