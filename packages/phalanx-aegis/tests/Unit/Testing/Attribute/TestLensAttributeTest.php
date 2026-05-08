<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Attribute;

use Phalanx\Testing\Attribute\TestLens;
use Phalanx\Tests\Fixtures\Testing\FixtureLens;
use Phalanx\Tests\Fixtures\Testing\FixtureLensFactory;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;

final class TestLensAttributeTest extends TestCase
{
    public function testFixtureLensExposesAttributeMetadata(): void
    {
        $reflection = new ReflectionClass(FixtureLens::class);
        $attributes = $reflection->getAttributes(TestLens::class);

        self::assertCount(1, $attributes);

        $attribute = $attributes[0]->newInstance();

        self::assertSame('fixture', $attribute->accessor);
        self::assertSame(FixtureLens::class, $attribute->returns);
        self::assertSame(FixtureLensFactory::class, $attribute->factory);
        self::assertSame([], $attribute->requires);
    }

    public function testAttributeAcceptsRequiresList(): void
    {
        $attribute = new TestLens(
            accessor: 'http',
            returns: FixtureLens::class,
            factory: FixtureLensFactory::class,
            requires: [\stdClass::class, \DateTimeInterface::class],
        );

        self::assertSame([\stdClass::class, \DateTimeInterface::class], $attribute->requires);
    }

    public function testAttributeReflectionIsInstanceOfAware(): void
    {
        $reflection = new ReflectionClass(FixtureLens::class);

        $attributes = $reflection->getAttributes(TestLens::class, ReflectionAttribute::IS_INSTANCEOF);

        self::assertCount(1, $attributes);
    }
}
