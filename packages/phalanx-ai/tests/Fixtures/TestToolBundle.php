<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Fixtures;

use Phalanx\Ai\Tool\ToolBundle;

final class TestToolBundle implements ToolBundle
{
    /** @return list<class-string<\Phalanx\Ai\Tool\Tool>> */
    public function tools(): array
    {
        return [
            EchoTool::class,
            CalculatorTool::class,
        ];
    }
}
