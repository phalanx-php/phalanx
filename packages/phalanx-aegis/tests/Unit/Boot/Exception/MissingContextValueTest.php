<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot\Exception;

use Phalanx\Boot\Exception\MissingContextValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingContextValueTest extends TestCase
{
    #[Test]
    public function forKeyIncludesKeyInMessage(): void
    {
        $exception = MissingContextValue::forKey('FOO');

        self::assertInstanceOf(MissingContextValue::class, $exception);
        self::assertStringContainsString('FOO', $exception->getMessage());
    }

    #[Test]
    public function wrongTypeIncludesKeyExpectedAndActualInMessage(): void
    {
        $exception = MissingContextValue::wrongType('K', 'string', 'array');

        self::assertInstanceOf(MissingContextValue::class, $exception);
        self::assertStringContainsString('K', $exception->getMessage());
        self::assertStringContainsString('string', $exception->getMessage());
        self::assertStringContainsString('array', $exception->getMessage());
    }
}
