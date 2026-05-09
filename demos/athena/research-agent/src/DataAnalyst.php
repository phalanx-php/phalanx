<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\Scope\ExecutionScope;

/**
 * A focused sub-agent for spreadsheet analysis.
 *
 * Spawned by QuerySpreadsheet when the user's question requires
 * calculations or lookups against tabular data.
 */
final class DataAnalyst implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
            You are a data analyst. Given column headers, row counts, and sample data
            from a spreadsheet, answer the specific query. Show your reasoning step by step.
            If the query requires calculations, perform them precisely.
            PROMPT;
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
