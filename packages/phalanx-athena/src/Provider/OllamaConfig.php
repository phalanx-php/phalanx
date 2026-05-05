<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

final readonly class OllamaConfig
{
    public function __construct(
        public string $model = 'llama3',
        public string $baseUrl = 'http://localhost:11434',
    ) {
    }
}
