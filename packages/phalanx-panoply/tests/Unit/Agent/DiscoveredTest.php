<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Agent;

use Phalanx\Panoply\Agent\Discovered;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiscoveredTest extends TestCase
{
    #[Test]
    public function attributeExists(): void
    {
        self::assertTrue(class_exists(Discovered::class));
    }

    #[Test]
    public function attributeTargetsClass(): void
    {
        $ref        = new \ReflectionClass(Discovered::class);
        $attributes = $ref->getAttributes(\Attribute::class);

        self::assertNotEmpty($attributes);

        /** @var \Attribute $attrInstance */
        $attrInstance = $attributes[0]->newInstance();

        self::assertSame(\Attribute::TARGET_CLASS, $attrInstance->flags);
    }

    #[Test]
    public function attributeCanBeAppliedToClass(): void
    {
        // Verify that reflection can pick up Discovered on a class decorated with it.
        $ref        = new \ReflectionClass(SampleDiscoveredAgent::class);
        $attributes = $ref->getAttributes(Discovered::class);

        self::assertCount(1, $attributes);
        self::assertInstanceOf(Discovered::class, $attributes[0]->newInstance());
    }

    #[Test]
    public function attributeIsFinalClass(): void
    {
        $ref = new \ReflectionClass(Discovered::class);

        self::assertTrue($ref->isFinal());
    }
}

#[Discovered]
final class SampleDiscoveredAgent
{
}
