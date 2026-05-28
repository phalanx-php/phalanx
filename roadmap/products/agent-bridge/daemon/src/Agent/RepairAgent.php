<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use AgentBridge\Lego\LegoDefinition;
use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Turn;
use Phalanx\ExecutionScope;

/**
 * Mid-tier repair agent. Invoked when a lego has 3+ consecutive failures.
 *
 * Provider 'repair' resolves to a mid-tier model (sonnet, gpt-4o-mini, etc.)
 * via ProviderConfig. The agent receives the broken lego, the current DOM,
 * and the error message. It proposes repaired steps with updated selectors.
 */
final class RepairAgent implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
        You are a browser automation repair specialist. A lego action sequence has failed because its CSS selectors no longer match the current DOM.

        Your task:
        1. Examine the broken lego's steps and the failure error.
        2. Analyze the current DOM snapshot to find updated selectors.
        3. Call repair_lego with corrected steps.

        Rules:
        - Keep the same logical sequence -- only update selectors, not the intent.
        - Prefer stable selectors: data attributes ([data-*]), aria attributes ([aria-*]), role ([role=*]), semantic IDs.
        - If the target element no longer exists at all, still provide the best available selector and note it in a comment.
        - You MUST call repair_lego -- do not respond with plain text only.
        PROMPT;
    }

    public function __construct(
        private readonly LegoDefinition $brokenLego,
        private readonly string $currentDom,
        private readonly string $error = '',
    ) {}

    public function tools(): array
    {
        return [RepairLego::class];
    }

    public function provider(): ?string
    {
        return 'repair';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $context = json_encode([
            'brokenLego' => $this->brokenLego->toArray(),
            'failureError' => $this->error,
            'currentDom' => $this->currentDom,
        ], JSON_THROW_ON_ERROR);

        $emitter = AgentLoop::run(
            Turn::begin($this)
                ->message("Repair the following broken lego:\n\n{$context}")
                ->maxSteps(5),
            $scope,
        );

        return AgentResult::awaitFrom($emitter, $scope);
    }
}
