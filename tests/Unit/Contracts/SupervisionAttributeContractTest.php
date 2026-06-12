<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Contracts;

use Attribute;
use InvalidArgumentException;
use Phalanx\Supervision\Identity;
use Phalanx\Supervision\Operation;
use Phalanx\Supervision\Redact;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SupervisionAttributeContractTest extends TestCase
{
    #[Test]
    public function supervisionAttributesExposeOnlyDeclarativeMetadata(): void
    {
        self::assertSame(['__construct'], $this->publicMethodNames(Operation::class));
        self::assertSame(['__construct'], $this->publicMethodNames(Identity::class));
        self::assertSame(['__construct'], $this->publicMethodNames(Redact::class));

        self::assertSame(['name' => 'string'], $this->publicPropertyTypes(Operation::class));
        self::assertSame(['name' => '?string'], $this->publicPropertyTypes(Identity::class));
        self::assertSame(['label' => '?string'], $this->publicPropertyTypes(Redact::class));
    }

    #[Test]
    public function supervisionAttributesAreFrozenMetadataContracts(): void
    {
        foreach ([Operation::class, Identity::class, Redact::class] as $class) {
            $reflection = new ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), "{$class} should not be extended.");
            self::assertTrue($reflection->isReadOnly(), "{$class} should remain immutable metadata.");
        }
    }

    #[Test]
    public function supervisionAttributesDeclareExactTargets(): void
    {
        self::assertSame(Attribute::TARGET_CLASS, $this->attributeFlags(Operation::class));
        self::assertSame(
            Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY,
            $this->attributeFlags(Identity::class),
        );
        self::assertSame(
            Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY,
            $this->attributeFlags(Redact::class),
        );
    }

    #[Test]
    public function supervisionAttributesCarryTypedMetadata(): void
    {
        $operation = new Operation('billing.charge');
        $identity = new Identity('customerId');
        $anonymousIdentity = new Identity();
        $redact = new Redact('secret');
        $anonymousRedact = new Redact();

        self::assertSame('billing.charge', $operation->name);
        self::assertSame('customerId', $identity->name);
        self::assertNull($anonymousIdentity->name);
        self::assertSame('secret', $redact->label);
        self::assertNull($anonymousRedact->label);
    }

    #[Test]
    public function supervisionAttributesRejectEmptyOperationNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation name cannot be empty.');

        new Operation('');
    }

    #[Test]
    public function supervisionAttributesRejectEmptyIdentityNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Identity name cannot be empty.');

        new Identity('');
    }

    #[Test]
    public function supervisionAttributesRejectEmptyRedactionLabels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redaction label cannot be empty.');

        new Redact('');
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
