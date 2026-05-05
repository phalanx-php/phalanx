<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

final readonly class GeminiConfig
{
    public function __construct(
        public string $apiKey,
        public string $model = 'gemini-1.5-flash',
        public string $baseUrl = 'https://generativelanguage.googleapis.com',
        public int $maxTokens = 4096,
    ) {
    }
}
