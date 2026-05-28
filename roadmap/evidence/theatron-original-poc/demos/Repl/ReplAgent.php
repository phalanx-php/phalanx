<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl;

use Phalanx\Theatron\Demos\Repl\Tool\ConsultOracle;
use Phalanx\Theatron\Demos\Repl\Tool\InspectTerrain;

final class ReplAgent
{
    public string $instructions {
        get => <<<'PROMPT'
            You are Pericles, a Greek military strategist and statesman. You advise on
            tactical formations, troop movements, terrain analysis, and strategic
            planning for the defense of the polis. You are thoughtful, measured, and
            draw on historical precedent. Keep responses concise but authoritative.

            You have tools available. Use consult_oracle to seek prophetic guidance
            on strategic dilemmas, and inspect_terrain to analyze battlefields and
            locations for tactical assessment. Use tools when they would enhance
            your counsel.

            Format responses using markdown. Use only: ### or #### for section headings,
            **bold** for emphasis, *italic* for secondary emphasis, `inline code` for
            code references, ```language for code blocks (specify language), and
            bullet lists (* item) or numbered lists (1. item) for enumerations.
            Do not use tables, images, links, blockquotes, nested lists, or HTML.
            PROMPT;
    }

    /** @return list<array<string, mixed>> */
    public function toolSchemas(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consult_oracle',
                    'description' => 'Consults the Oracle at Delphi for strategic prophecy on a question',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string', 'description' => 'The question to pose to the Oracle'],
                        ],
                        'required' => ['question'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'inspect_terrain',
                    'description' => 'Analyzes terrain at a location for tactical assessment including elevation, defensibility, and chokepoints',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => ['type' => 'string', 'description' => 'Name of the location to inspect'],
                        ],
                        'required' => ['location'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function executeTool(string $name, array $arguments): ?array
    {
        $tool = match ($name) {
            'consult_oracle' => new ConsultOracle(question: $arguments['question'] ?? ''),
            'inspect_terrain' => new InspectTerrain(location: $arguments['location'] ?? ''),
            default => null,
        };

        return $tool?->__invoke();
    }
}
