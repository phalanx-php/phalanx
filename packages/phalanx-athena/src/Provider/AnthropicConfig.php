<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

final readonly class AnthropicConfig
{
    public function __construct(
        public string $apiKey,
        public string $model = 'claude-sonnet-4-20250514',
        public string $baseUrl = 'https://api.anthropic.com',
        public string $apiVersion = '2023-06-01',
        public int $maxTokens = 4096,
    ) {}
}
