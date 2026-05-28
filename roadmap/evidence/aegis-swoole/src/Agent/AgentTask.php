<?php

declare(strict_types=1);

namespace AegisSwoole\Agent;

use AegisSwoole\Llm\LlmClient;
use AegisSwoole\Llm\LlmConfig;
use AegisSwoole\Scope\DeferredScope;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;

/**
 * Worker-shippable agent task. The agent has a role (system prompt) and a
 * transcript of prior turns. Each invocation appends the parent's latest
 * message and returns the assistant's reply.
 *
 * The agent is stateless across invocations — the parent owns the canonical
 * transcript and ships the full history each turn. This keeps the worker
 * pool stateless and lets the parent route turns to any available worker.
 *
 * Configuration (LlmConfig with API key) ships in the serialized payload so
 * the child worker doesn't need its own service container or key plumbing.
 */
class AgentTask implements Executable
{
    /** @param list<array{role: string, content: string}> $transcript */
    public function __construct(
        public readonly string $role,
        public readonly string $systemPrompt,
        public readonly array $transcript,
        public readonly LlmConfig $config,
        public readonly string $model = 'llama3:8b',
        public readonly int $maxTokens = 256,
    ) {
    }

    public function __invoke(ExecutionScope $scope): string
    {
        $client = new LlmClient(new DeferredScope(), $this->config);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt],
            ...$this->transcript,
        ];
        return $client->complete($this->model, $messages, maxTokens: $this->maxTokens);
    }
}
