<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Context;

use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Styling\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContextTest extends TestCase
{
    #[Test]
    public function renderContextDoesNotExposeUiOrMountAuthoring(): void
    {
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mount = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mount);
        $reflection = new ReflectionClass($ctx);

        self::assertFalse($reflection->hasProperty('ui'));
        self::assertNotContains('mount', self::publicMethodNames($reflection));
    }

    #[Test]
    public function screenContextDoesNotExposeUiOrMountAuthoring(): void
    {
        $scope = $this->createStub(\Phalanx\Scope\TaskScope::class);
        $navigator = $this->createStub(Navigator::class);
        $mount = new MountSystem($scope);
        $ctx = new ScreenContext($scope, Theme::default(), $navigator, $mount);
        $reflection = new ReflectionClass($ctx);

        self::assertFalse($reflection->hasProperty('ui'));
        self::assertNotContains('mount', self::publicMethodNames($reflection));
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
     * @return list<string>
     */
    private static function publicMethodNames(ReflectionClass $reflection): array
    {
        return array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );
    }
}
