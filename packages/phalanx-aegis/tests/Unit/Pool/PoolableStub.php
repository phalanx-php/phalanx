<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Pool\BorrowedValue;

final class PoolableStub implements BorrowedValue
{
    public string $name = '';
    public int $value = 0;
}
