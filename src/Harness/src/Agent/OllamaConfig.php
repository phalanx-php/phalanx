<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\ContextKey;
use Phalanx\Boot\ContextSchema;
use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;

final class OllamaConfig implements Config
{
    private const string DEFAULT_BASE_URL = 'http://localhost:11434';
    private const string DEFAULT_MODEL = 'qwen3:4b';
    private const int DEFAULT_MAX_INVOCATIONS = 3;

    /** Computed from the minimum values needed to route an Ollama activity. */
    public bool $configured {
        get => $this->baseUrl !== '' && $this->model !== '' && $this->maxInvocations > 0;
    }

    public function __construct(
        #[Env(key: 'HARNESS_OLLAMA_BASE_URL', description: 'Ollama API base URL')]
        private(set) string $baseUrl = self::DEFAULT_BASE_URL,
        #[Env(key: 'HARNESS_OLLAMA_MODEL', description: 'Default harness chat model')]
        private(set) string $model = self::DEFAULT_MODEL,
        #[Env(key: 'HARNESS_MAX_INVOCATIONS', description: 'Maximum agent invocations per activity')]
        private(set) int $maxInvocations = self::DEFAULT_MAX_INVOCATIONS,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromContext(AppContext $context): self
    {
        return new self(
            baseUrl: $context->string('HARNESS_OLLAMA_BASE_URL', self::DEFAULT_BASE_URL),
            model: $context->string('HARNESS_OLLAMA_MODEL', self::DEFAULT_MODEL),
            maxInvocations: $context->int('HARNESS_MAX_INVOCATIONS', self::DEFAULT_MAX_INVOCATIONS),
        );
    }

    public static function contextSchema(): ContextSchema
    {
        return ContextSchema::of(
            ContextKey::optional('HARNESS_OLLAMA_BASE_URL', self::DEFAULT_BASE_URL, 'Ollama API base URL', 'string'),
            ContextKey::optional('HARNESS_OLLAMA_MODEL', self::DEFAULT_MODEL, 'Default harness chat model', 'string'),
            ContextKey::optional(
                'HARNESS_MAX_INVOCATIONS',
                (string) self::DEFAULT_MAX_INVOCATIONS,
                'Maximum agent invocations per activity',
                'int',
            ),
        );
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        $issues = [];

        if ($this->baseUrl === '') {
            $issues[] = new Issue(
                IssueLevel::Error,
                'ollama.base-url-empty',
                'Ollama base URL cannot be empty.',
                envKey: 'HARNESS_OLLAMA_BASE_URL',
                path: 'baseUrl',
            );
        }

        if ($this->model === '') {
            $issues[] = new Issue(
                IssueLevel::Error,
                'ollama.model-empty',
                'Ollama model cannot be empty.',
                envKey: 'HARNESS_OLLAMA_MODEL',
                path: 'model',
            );
        }

        if ($this->maxInvocations < 1) {
            $issues[] = new Issue(
                IssueLevel::Error,
                'ollama.max-invocations',
                'HARNESS_MAX_INVOCATIONS must be at least 1.',
                envKey: 'HARNESS_MAX_INVOCATIONS',
                path: 'maxInvocations',
            );
        }

        return $issues;
    }
}
