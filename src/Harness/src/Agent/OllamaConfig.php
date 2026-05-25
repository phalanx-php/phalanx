<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Boot\AppContext;

final class OllamaConfig
{
    private const string DEFAULT_BASE_URL = 'http://localhost:11434';
    private const string DEFAULT_MODEL = 'qwen3:4b';
    private const int DEFAULT_MAX_INVOCATIONS = 3;

    public function __construct(
        private(set) string $baseUrl = self::DEFAULT_BASE_URL,
        private(set) string $model = self::DEFAULT_MODEL,
        private(set) int $maxInvocations = self::DEFAULT_MAX_INVOCATIONS,
    ) {
    }

    public static function fromContext(AppContext $context): self
    {
        return new self(
            baseUrl: $context->string('HARNESS_OLLAMA_BASE_URL', self::DEFAULT_BASE_URL),
            model: $context->string('HARNESS_OLLAMA_MODEL', self::DEFAULT_MODEL),
            maxInvocations: $context->int('HARNESS_MAX_INVOCATIONS', self::DEFAULT_MAX_INVOCATIONS),
        );
    }
}
