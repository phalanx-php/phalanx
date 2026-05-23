<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Contract;

use Phalanx\Theatron\Contract\Disposable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InterfaceTest extends TestCase
{
    #[Test]
    public function theatronDisposableIsNotAegisDisposable(): void
    {
        self::assertNotSame(
            Disposable::class,
            \Phalanx\Scope\Disposable::class,
        );
    }
}
