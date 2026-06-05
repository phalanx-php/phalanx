<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testRequiredGatesHealth(): void
    {
        self::assertTrue(Severity::Required->gates());
    }

    public function testOptionalDoesNotGateHealth(): void
    {
        self::assertFalse(Severity::Optional->gates());
    }

    public function testInformationalDoesNotGateHealth(): void
    {
        self::assertFalse(Severity::Informational->gates());
    }

    public function testBackingValues(): void
    {
        self::assertSame('required', Severity::Required->value);
        self::assertSame('optional', Severity::Optional->value);
        self::assertSame('info', Severity::Informational->value);
    }
}
