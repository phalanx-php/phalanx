<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Pool\BorrowedValue;

final class ScopedBorrowedAgentEvent implements BorrowedValue
{
}

final class BorrowedValueBoundaryScopeFixture
{
    public function invalidClosureVariableReturn(ScopedBorrowedAgentEvent $event): \Closure
    {
        $fn = static function () use ($event): void {
            $event::class;
        };

        return $fn;
    }

    public function validSameVariableNameInDifferentMethod(\Closure $fn): \Closure
    {
        return $fn;
    }
}
