<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Pool\BorrowedValue;

final class MutableStub implements BorrowedValue
{
    public string $label = '';
}
