<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Styx\Emitter;

/**
 * AgentDefinition adapter for a Specialist.
 *
 * Built per-call from a Specialist + assembled system prompt. Stateless: no
 * conversation history is retained across calls — that's the design intent
 * of background specialists.
 */
final class SpecialistAgent implements AgentDefinition
{
    public string $instructions {
        get => $this->systemPrompt;
    }

    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $providerName,
        private readonly string $modelName,
    ) {}

    public function tools(): array
    {
        return [];
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function __invoke(ExecutionScope $scope): Emitter
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }
}
