<?php

declare(strict_types=1);

namespace Phalanx\Agentic\AgentSession;

use Phalanx\Agentic\Signal\FinalAnswerSignal;
use Phalanx\Agentic\Signal\ThinkingSignal;
use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Memory\ConversationMemory;
use Phalanx\Athena\Stream\TokenAccumulator;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;

final class AgentSession implements Executable
{
    private AgentSessionState $state;

    public function __construct(
        private readonly SessionConfig $config,
        private readonly ConversationMemory $memory,
        private readonly AgentDefinition $agent,
    ) {
        $this->state = AgentSessionState::idle();
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $this->state = $this->state->withStatus('running');

        // Emit thinking signal before execution
        $collector = $scope->service(\Phalanx\Eidolon\Signal\SignalCollector::class);
        $collector->add(new ThinkingSignal($this->config->sessionId, 'Starting agent loop...', 0));

        // Real Athena execution using Task + static run
        $agent = $this->agent;
        $memory = $this->memory;

        $result = Task::of(static function (ExecutionScope $es) use ($agent, $memory) {
            return AgentLoop::run($agent, $memory, $es);
        })($scope);

        // Emit final signal
        $collector->add(new FinalAnswerSignal(
            $this->config->sessionId,
            (string)($result['answer'] ?? 'Task completed'),
            (int)($result['tokens'] ?? 0)
        ));

        $scope->onDispose(static fn() => $this->persist());

        return $this->state;
    }

    public function handleUserMessage(ExecutionScope $scope, string $message, array $attachments = []): void
    {
        $this->state = $this->state->withLastSignal([
            'type'    => 'user_message',
            'message' => $message,
        ]);
    }

    public function pause(): void
    {
        $this->state = $this->state->withStatus('paused');
    }

    public function cancel(string $reason = 'user_request'): void
    {
        $this->state = $this->state
            ->withStatus('cancelled')
            ->withLastSignal(['type' => 'cancellation', 'reason' => $reason]);
    }

    public function getState(): AgentSessionState
    {
        return $this->state;
    }

    public function getConfig(): SessionConfig
    {
        return $this->config;
    }

    private function persist(): void
    {
        // TODO: save to PgConversationMemory
    }
}
