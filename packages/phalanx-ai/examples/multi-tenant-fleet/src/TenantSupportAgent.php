<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\Turn;
use Phalanx\ExecutionScope;

/**
 * A tenant-specific support agent with runtime-configured tools and prompt.
 *
 * Built by TenantAgentFactory from database configuration. Each tenant
 * gets their own system prompt, tool set, and provider preference.
 */
final class TenantSupportAgent implements AgentDefinition
{
    public string $instructions {
        get => $this->systemInstructions;
    }

    /**
     * @param list<class-string<\Phalanx\Ai\Tool\Tool>> $toolClasses
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $systemInstructions,
        private readonly string $providerName,
        private readonly array $toolClasses,
    ) {}

    public function tools(): array
    {
        return $this->toolClasses;
    }

    public function provider(): ?string
    {
        return $this->providerName;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }
}
