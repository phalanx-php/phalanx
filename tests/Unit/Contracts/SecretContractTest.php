<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Contracts;

use Phalanx\Invocation\Secret;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameter;

final class SecretContractTest extends TestCase
{
    #[Test]
    public function secretCarriesTheEngineSensitiveParameterAttributeOnItsOwnConstructor(): void
    {
        $constructor = (new ReflectionClass(Secret::class))->getConstructor();

        self::assertNotNull($constructor);

        $parameters = $constructor->getParameters();

        self::assertCount(1, $parameters);
        self::assertCount(1, $parameters[0]->getAttributes(SensitiveParameter::class));
    }

    #[Test]
    public function secretMasksEveryProjectionSurface(): void
    {
        $secret = new Secret('sk-live-very-secret');

        self::assertSame('[redacted]', (string) $secret);
        self::assertSame(['value' => '[redacted]'], $secret->__debugInfo());
        self::assertSame('"[redacted]"', json_encode($secret, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('sk-live-very-secret', var_export($secret->__debugInfo(), true));
    }

    #[Test]
    public function revealIsTheOnlyDoorToTheClearText(): void
    {
        $secret = new Secret('sk-live-very-secret');

        self::assertSame('sk-live-very-secret', $secret->reveal());
    }

    #[Test]
    public function configurationStateDerivesFromTheRawValue(): void
    {
        self::assertTrue(new Secret('token')->configured);
        self::assertFalse(Secret::empty()->configured);
        self::assertSame('', Secret::empty()->reveal());
    }

    #[Test]
    public function secretIsASealedValueType(): void
    {
        $reflection = new ReflectionClass(Secret::class);

        self::assertTrue($reflection->isFinal());
    }
}
