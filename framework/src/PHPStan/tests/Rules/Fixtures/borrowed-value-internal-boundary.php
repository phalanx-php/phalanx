<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Pool\BorrowedValue;

final class InternalBorrowedAgentEvent implements BorrowedValue
{
}

final class BorrowedValueInternalBoundaryFixture
{
    private ?InternalBorrowedAgentEvent $stored = null;

    public function start(InternalBorrowedAgentEvent $event): InternalBorrowedAgentEvent
    {
        $this->stored = $event;

        return $event;
    }
}
