<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Grant\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrantTypesTest extends TestCase
{
    #[Test]
    public function scopeCasesMatchGrantLifetimes(): void
    {
        self::assertSame('once', Scope::Once->value);
        self::assertSame('session', Scope::Session->value);
        self::assertSame('always', Scope::Always->value);
        self::assertSame('dynamic', Scope::Dynamic->value);
    }
}
