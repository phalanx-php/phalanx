<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Fixtures;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;

final class CalculatorTool implements Tool
{
    public string $description {
        get => 'Performs basic arithmetic calculations';
    }

    public function __construct(
        #[Param('First operand')]
        private readonly float $a,
        #[Param('Second operand')]
        private readonly float $b,
        #[Param('Operation: add, subtract, multiply, divide')]
        private readonly string $operation = 'add',
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $result = match ($this->operation) {
            'add' => $this->a + $this->b,
            'subtract' => $this->a - $this->b,
            'multiply' => $this->a * $this->b,
            'divide' => $this->b !== 0.0 ? $this->a / $this->b : 'Division by zero',
            default => 'Unknown operation',
        };

        return ToolOutcome::data(['result' => $result, 'expression' => "{$this->a} {$this->operation} {$this->b}"]);
    }
}
