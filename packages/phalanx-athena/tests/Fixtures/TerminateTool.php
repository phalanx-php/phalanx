<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Fixtures;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;

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
