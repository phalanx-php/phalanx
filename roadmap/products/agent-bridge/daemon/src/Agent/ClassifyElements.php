<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope;

/**
 * Terminal tool for the ClassifierAgent.
 *
 * The AI calls this once with its full classification decisions.
 * ToolOutcome::done() terminates the agent loop immediately -- the classifier
 * is single-turn by design.
 */
final class ClassifyElements implements Tool
{
    public string $description {
        get => 'Classify DOM elements against available legos. Return a list of classifications, each mapping a lego name to the elements it should act on.';
    }

    public array $tags {
        get => ['classification'];
    }

    /**
     * @param list<array{legoName: string, confidence: float, elementIndices: list<int>}> $classifications
     */
    public function __construct(
        #[Param('Array of classification objects. Each has legoName (string), confidence (float 0-1), and elementIndices (array of int indices into the DOM elements list).')]
        public readonly array $classifications,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $valid = array_values(array_filter(
            $this->classifications,
            static function (mixed $c): bool {
                if (!is_array($c)) {
                    return false;
                }

                return isset($c['legoName'], $c['confidence'])
                    && is_string($c['legoName'])
                    && (is_float($c['confidence']) || is_int($c['confidence']));
            },
        ));

        return ToolOutcome::done($valid);
    }
}
