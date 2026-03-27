<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Fixtures;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class TerminateTool implements Tool
{
    public string $description {
        get => 'Terminates the agent loop with a message';
    }

    public function __construct(
        #[Param('The final message')]
        private readonly string $finalMessage,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        return ToolOutcome::done($this->finalMessage, reason: 'Tool terminated');
    }
}
