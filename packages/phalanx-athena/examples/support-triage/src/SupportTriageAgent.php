<?php

declare(strict_types=1);

namespace Acme;

use Acme\Tools\CheckServiceStatus;
use Acme\Tools\GetRecentTickets;
use Acme\Tools\LookupCustomer;
use Acme\Tools\SearchKnowledgeBase;
use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\HasTimeout;

final class SupportTriageAgent implements AgentDefinition, HasTimeout
{
    public string $instructions {
        get => <<<'PROMPT'
            You are a senior support specialist. When given a support ticket:

            1. Classify its priority (critical/high/medium/low) and category
               (billing/technical/account/feature-request/bug-report)
            2. Look up the customer's account details and recent activity
            3. Check the knowledge base for relevant articles
            4. Draft a response that addresses the customer's issue directly

            If the issue is critical (service down, data loss, security),
            set priority to critical and include escalation instructions.
            If you can fully resolve the issue from the knowledge base,
            mark it as auto-resolvable.
            PROMPT;
    }

    public float $timeout {
        get => 25.0;
    }

    public function tools(): array
    {
        return [
            LookupCustomer::class,
            SearchKnowledgeBase::class,
            GetRecentTickets::class,
            CheckServiceStatus::class,
        ];
    }

    public function provider(): ?string
    {
        return 'anthropic';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }
}
