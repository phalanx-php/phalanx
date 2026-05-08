<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\Scope\ExecutionScope;

/**
 * A tenant-specific support agent with runtime-configured tools and prompt.
 *
 * Built by TenantAgentFactory from database configuration. Each tenant
 * gets their own system prompt, tool set, and provider preference.
 */
class TenantSupportAgent implements AgentDefinition
{
    public string $instructions {
        get => $this->systemInstructions;
    }

    /**
     * @param list<class-string<\Phalanx\Athena\Tool\Tool>> $toolClasses
     */
    public function __construct(
        private string $tenantId,
        private string $systemInstructions,
        private string $providerName,
        private array $toolClasses,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }

    public function tools(): array
    {
        return $this->toolClasses;
    }

    public function provider(): ?string
    {
        return $this->providerName;
    }
}
