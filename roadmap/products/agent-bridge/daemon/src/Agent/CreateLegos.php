<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope;

/**
 * Terminal tool for the GeneratorAgent.
 *
 * The AI submits its proposed lego definitions here. Each lego is validated
 * for structure and op legality before being returned. ToolOutcome::done()
 * terminates the agent loop; the caller constructs LegoDefinition objects
 * and persists via LegoLibrary.
 */
final class CreateLegos implements Tool
{
    public string $description {
        get => 'Submit generated lego definitions for a website. Each lego is a named, reusable action sequence with CSS selector targets.';
    }

    public array $tags {
        get => ['generation'];
    }

    private const VALID_OPS = [
        'click', 'clickAll', 'type', 'fill', 'select', 'check', 'press',
        'scroll', 'waitForSelector', 'waitForRemoval', 'waitForText',
        'waitForNetwork', 'getAttribute', 'getTextContent', 'evaluate', 'delay',
    ];

    /**
     * @param list<array{name: string, description: string, steps: list<array{op: string, selector?: string, value?: string, timeoutMs?: int}>}> $legos
     */
    public function __construct(
        #[Param('Array of lego definitions. Each has name (string), description (string), and steps (array of step objects with op, selector, value, timeoutMs fields).')]
        public readonly array $legos,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $validated = [];

        foreach ($this->legos as $lego) {
            if (!is_array($lego) || !isset($lego['name'], $lego['steps']) || !is_array($lego['steps'])) {
                continue;
            }

            $validSteps = array_values(array_filter(
                $lego['steps'],
                static function (mixed $step): bool {
                    return is_array($step)
                        && isset($step['op'])
                        && in_array($step['op'], self::VALID_OPS, true);
                },
            ));

            if ($validSteps === []) {
                continue;
            }

            $validated[] = [
                'name' => $lego['name'],
                'description' => $lego['description'] ?? '',
                'steps' => $validSteps,
            ];
        }

        return ToolOutcome::done($validated);
    }
}
