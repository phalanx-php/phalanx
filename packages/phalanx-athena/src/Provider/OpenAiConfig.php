<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

final readonly class OpenAiConfig
{
    public function __construct(
        public string $apiKey,
        public string $model = 'gpt-4o',
        public string $baseUrl = 'https://api.openai.com',
        public int $maxTokens = 4096,
    ) {
    }
}
