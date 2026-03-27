<?php

declare(strict_types=1);

namespace Phalanx\Integration\Ai;

final class AiConfig
{
    public function __construct(
        public private(set) string $claudeApiKey,
        public private(set) string $claudeModel = 'claude-sonnet-4-5',
        public private(set) string $claudeEndpoint = 'https://api.anthropic.com/v1/messages',
        public private(set) string $openaiApiKey = '',
        public private(set) string $openaiModel = 'gpt-4.1-mini',
        public private(set) string $openaiEndpoint = 'https://api.openai.com/v1/chat/completions',
        public private(set) int $maxTokens = 1024,
    ) {}
}
