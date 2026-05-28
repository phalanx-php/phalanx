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
 * Cheap, high-frequency classifier. Runs on every batch from the stream pipeline.
 *
 * Provider 'classifier' resolves to the cheapest available model (haiku, local
 * Ollama, etc.) via ProviderConfig. This agent is single-turn: the AI calls
 * ClassifyElements once and the loop terminates.
 */
final class ClassifierAgent implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
        You are a browser automation classifier. Given a list of DOM elements and a set of available lego action sequences, identify which legos should be executed given the current page state.

        Rules:
        - Only classify if you are confident the lego applies to the current DOM context.
        - Use the classify_elements tool exactly once with all classifications.
        - Each classification must reference a lego name from the provided list.
        - Confidence should reflect how well the DOM elements match the lego's expected target.
        - Do not classify if no legos apply to the current state -- return an empty array.
        PROMPT;
    }

    /**
     * @param list<LegoDefinition> $availableLegos
     * @param list<array<string, mixed>> $domElements
     * @param list<array<string, mixed>>|null $policyRules
     */
    public function __construct(
        private readonly array $availableLegos,
        private readonly array $domElements,
        private readonly ?array $policyRules,
    ) {}

    public function tools(): array
    {
        return [ClassifyElements::class];
    }

    public function provider(): ?string
    {
        return 'classifier';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $legoNames = array_map(static fn(LegoDefinition $l): string => $l->name, $this->availableLegos);
        $legoDescriptions = array_map(
            static fn(LegoDefinition $l): string => "{$l->name}: {$l->description}",
            $this->availableLegos,
        );

        $context = json_encode([
            'availableLegos' => $legoDescriptions,
            'domElements' => $this->domElements,
            'policyRules' => $this->policyRules,
        ], JSON_THROW_ON_ERROR);

        $emitter = AgentLoop::run(
            Turn::begin($this)
                ->message("Classify the following page context:\n\n{$context}")
                ->maxSteps(3),
            $scope,
        );

        return AgentResult::awaitFrom($emitter, $scope);
    }
}
