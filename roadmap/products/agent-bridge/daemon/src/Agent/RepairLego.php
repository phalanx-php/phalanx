<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope;

/**
 * Tool for the RepairAgent.
 *
 * ToolOutcome::retry() gives the AI another attempt with a corrective hint
 * when it submits empty steps -- the lego cannot be repaired without steps.
 * ToolOutcome::done() terminates the loop and hands repaired steps back to
 * the caller, which calls LegoDefinition::withRepairedSteps() and saves.
 */
final class RepairLego implements Tool
{
    public string $description {
        get => 'Submit repaired steps for a broken lego. The steps should use updated CSS selectors that match the current DOM structure.';
    }

    public array $tags {
        get => ['repair'];
    }

    /**
     * @param list<array{op: string, selector?: string, value?: string, timeoutMs?: int}> $steps
     */
    public function __construct(
        #[Param('Array of repaired step objects. Same format as original lego steps but with corrected selectors.')]
        public readonly array $steps,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        if ($this->steps === []) {
            return ToolOutcome::retry('No steps provided. Analyze the DOM and provide repaired steps.');
        }

        return ToolOutcome::done($this->steps);
    }
}
