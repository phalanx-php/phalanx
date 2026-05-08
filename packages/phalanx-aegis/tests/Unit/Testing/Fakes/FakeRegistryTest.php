<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Fakes;

use InvalidArgumentException;
use Phalanx\Testing\Fakes\FakeRegistry;
use PHPUnit\Framework\TestCase;
use stdClass;

final class FakeRegistryTest extends TestCase
{
    public function testRegisteredFakeIsRetrievable(): void
    {
        $registry = new FakeRegistry();
        $fake = new stdClass();

        $registry->register(stdClass::class, $fake);

        self::assertTrue($registry->has(stdClass::class));
        self::assertSame($fake, $registry->get(stdClass::class));
    }

    public function testGetReturnsNullForUnregisteredService(): void
    {
        $registry = new FakeRegistry();

        self::assertFalse($registry->has(stdClass::class));
        self::assertNull($registry->get(stdClass::class));
    }

    public function testRegisterRejectsTypeMismatch(): void
    {
        $registry = new FakeRegistry();
        $service = FakeRegistryTestInterface::class;
        $wrongFake = new stdClass();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fake for ' . $service . ' must be an instance of that type; got stdClass.');

        $registry->register($service, $wrongFake);
    }

    public function testRegisterAcceptsSubclassInstance(): void
    {
        $registry = new FakeRegistry();
        $fake = new FakeRegistryTestImpl();

        $registry->register(FakeRegistryTestInterface::class, $fake);

        self::assertSame($fake, $registry->get(FakeRegistryTestInterface::class));
    }

    public function testResetClearsAllBindings(): void
    {
        $registry = new FakeRegistry();
        $registry->register(stdClass::class, new stdClass());
        $registry->register(FakeRegistryTestInterface::class, new FakeRegistryTestImpl());

        $registry->reset();

        self::assertFalse($registry->has(stdClass::class));
        self::assertFalse($registry->has(FakeRegistryTestInterface::class));
        self::assertSame([], $registry->all());
    }

    public function testAllReturnsAllRegisteredBindings(): void
    {
        $registry = new FakeRegistry();
        $first = new stdClass();
        $second = new FakeRegistryTestImpl();

        $registry->register(stdClass::class, $first);
        $registry->register(FakeRegistryTestInterface::class, $second);

        self::assertSame(
            [stdClass::class => $first, FakeRegistryTestInterface::class => $second],
            $registry->all(),
        );
    }

    public function testRegisterOverwritesPreviousBindingForSameService(): void
    {
        $registry = new FakeRegistry();
        $first = new stdClass();
        $second = new stdClass();

        $registry->register(stdClass::class, $first);
        $registry->register(stdClass::class, $second);

        self::assertSame($second, $registry->get(stdClass::class));
    }
}

interface FakeRegistryTestInterface
{
}

final class FakeRegistryTestImpl implements FakeRegistryTestInterface
{
}
