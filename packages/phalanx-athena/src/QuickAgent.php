<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Scope\ExecutionScope;

final class QuickAgent implements AgentDefinition
{
    public string $instructions {
        get => $this->systemPrompt;
    }

    public function __construct(
        private readonly string $systemPrompt,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }

    public function tools(): array
    {
        return [];
    }

    public function provider(): ?string
    {
        return null;
    }
}
