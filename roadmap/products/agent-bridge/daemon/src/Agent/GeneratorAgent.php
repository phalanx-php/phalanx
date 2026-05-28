<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Turn;
use Phalanx\ExecutionScope;

/**
 * Expensive, rare generator. Invoked once per new site from user chat intent.
 *
 * Provider 'generator' resolves to the most capable available model (opus,
 * gpt-4o, etc.) via ProviderConfig. This agent is multi-turn: it may call
 * ValidateSelector several times before calling CreateLegos to finalize.
 *
 * Scope attribute contract: the caller MUST pass 'tabScope' as a scope
 * attribute so ValidateSelector can query the live DOM.
 */
final class GeneratorAgent implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
        You are a browser automation lego generator. Given a DOM snapshot of a website and a user intent, create reusable action sequences (legos) that accomplish the intent.

        Rules:
        - Use validate_selector to verify each CSS selector you plan to use BEFORE calling create_legos.
        - Prefer stable selectors: data attributes ([data-*]), aria attributes ([aria-*]), role attributes ([role=*]), and semantic IDs.
        - Avoid auto-generated class names (hashes, hex strings, camelCase with random suffixes).
        - Each lego must have a meaningful name (kebab-case), a clear description, and steps using valid ops.
        - Steps execute sequentially in the content script. Include waits where navigation or async updates are expected.
        - Call create_legos once with all validated lego definitions.

        Valid step ops: click, clickAll, type, fill, select, check, press, scroll,
        waitForSelector, waitForRemoval, waitForText, waitForNetwork,
        getAttribute, getTextContent, evaluate, delay
        PROMPT;
    }

    public function __construct(
        private readonly string $domSnapshot,
        private readonly string $userIntent,
        private readonly ?string $domain,
    ) {}

    public function tools(): array
    {
        return [CreateLegos::class, ValidateSelector::class];
    }

    public function provider(): ?string
    {
        return 'generator';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $context = json_encode([
            'domain' => $this->domain,
            'userIntent' => $this->userIntent,
            'domSnapshot' => $this->domSnapshot,
        ], JSON_THROW_ON_ERROR);

        $emitter = AgentLoop::run(
            Turn::begin($this)
                ->message("Generate legos for the following context:\n\n{$context}")
                ->maxSteps(15),
            $scope,
        );

        return AgentResult::awaitFrom($emitter, $scope);
    }
}
