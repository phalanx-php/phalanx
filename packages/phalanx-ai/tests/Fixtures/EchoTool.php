<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Fixtures;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class EchoTool implements Tool
{
    public string $description {
        get => 'Echoes back the input message';
    }

    public function __construct(
        #[Param('The message to echo')]
        private readonly string $message,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        return ToolOutcome::data(['echo' => $this->message]);
    }
}
