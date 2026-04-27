<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Fixtures;

use Phalanx\Athena\Tool\ToolBundle;

final class TestToolBundle implements ToolBundle
{
    /** @return list<class-string<\Phalanx\Athena\Tool\Tool>> */
    public function tools(): array
    {
        return [
            EchoTool::class,
            CalculatorTool::class,
        ];
    }
}
