<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Contracts;

use Attribute;
use InvalidArgumentException;
use Phalanx\Scope\Scope;
use Phalanx\Supervision\IdempotencyPart;
use Phalanx\Supervision\Redact;
use Phalanx\Supervision\Trace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SupervisionAttributeContractTest extends TestCase
{
    #[Test]
    public function supervisionAttributesExposeOnlyDeclarativeMetadata(): void
    {
        self::assertSame(['__construct'], $this->publicMethodNames(Trace::class));
        self::assertSame(['__construct'], $this->publicMethodNames(IdempotencyPart::class));
        self::assertSame(['__construct'], $this->publicMethodNames(Redact::class));

        self::assertSame(['name' => 'string'], $this->publicPropertyTypes(Trace::class));
        self::assertSame(['name' => '?string'], $this->publicPropertyTypes(IdempotencyPart::class));
        self::assertSame(['label' => '?string'], $this->publicPropertyTypes(Redact::class));
    }

    #[Test]
    public function supervisionAttributesAreFrozenMetadataContracts(): void
    {
        foreach ([Trace::class, IdempotencyPart::class, Redact::class] as $class) {
            $reflection = new ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), "{$class} should not be extended.");
            self::assertTrue($reflection->isReadOnly(), "{$class} should remain immutable metadata.");
        }
    }

    #[Test]
    public function supervisionAttributesDeclareExactTargets(): void
    {
        self::assertSame(Attribute::TARGET_CLASS, $this->attributeFlags(Trace::class));
        self::assertSame(
            Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY,
            $this->attributeFlags(IdempotencyPart::class),
        );
        self::assertSame(
            Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY,
            $this->attributeFlags(Redact::class),
        );
    }

    #[Test]
    public function supervisionAttributesCarryTypedMetadata(): void
    {
        $trace = new Trace('billing.charge');
        $idempotencyPart = new IdempotencyPart('customerId');
        $anonymousIdempotencyPart = new IdempotencyPart();
        $redact = new Redact('secret');
        $anonymousRedact = new Redact();

        self::assertSame('billing.charge', $trace->name);
        self::assertSame('customerId', $idempotencyPart->name);
        self::assertNull($anonymousIdempotencyPart->name);
        self::assertSame('secret', $redact->label);
        self::assertNull($anonymousRedact->label);
    }

    #[Test]
    public function supervisionAttributesRejectEmptyMetadataNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Trace name cannot be empty.');

        new Trace('');
    }

    #[Test]
    public function supervisionAttributesRejectEmptyIdempotencyNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency part name cannot be empty.');

        new IdempotencyPart('');
    }

    #[Test]
    public function supervisionAttributesRejectEmptyRedactionLabels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redaction label cannot be empty.');

        new Redact('');
    }

    #[Test]
    public function scopeRemainsBehaviorFreeUntilTheInvocationKernelOwnsExecution(): void
    {
        $reflection = new ReflectionClass(Scope::class);

        self::assertSame([], $reflection->getMethods());
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function publicMethodNames(string $class): array
    {
        return array_map(
            static fn ($method): string => $method->getName(),
            (new ReflectionClass($class))->getMethods(),
        );
    }

    /**
     * @param class-string $class
     *
     * @return array<string, string>
     */
    private function publicPropertyTypes(string $class): array
    {
        $types = [];

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $type = $property->getType();

            self::assertNotNull($type);

            $types[$property->getName()] = $type->__toString();
        }

        return $types;
    }

    /** @param class-string $class */
    private function attributeFlags(string $class): int
    {
        $attributes = (new ReflectionClass($class))->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);

        $attribute = $attributes[0]->newInstance();

        self::assertInstanceOf(Attribute::class, $attribute);

        return $attribute->flags;
    }
}
