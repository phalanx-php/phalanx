<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Scope\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ScopeContractTest extends TestCase
{
    #[Test]
    public function theKernelOwnsExactlyTheSevenOperationsPlusStateReadsAndNarrowing(): void
    {
        $methods = array_map(
            static fn ($method): string => $method->getName(),
            new ReflectionClass(Scope::class)->getMethods(),
        );

        sort($methods);

        self::assertSame([
            'cancel',
            'faultsAs',
            'isCancelled',
            'map',
            'onErr',
            'parallel',
            'race',
            'remaining',
            'run',
            'series',
            'withDeadline',
            'withRetry',
            'withoutRetry',
        ], $methods);
    }

    #[Test]
    public function narrowingMethodsReturnScopesNeverConcreteBackends(): void
    {
        $reflection = new ReflectionClass(Scope::class);

        foreach (['withRetry', 'withDeadline', 'withoutRetry', 'faultsAs'] as $narrowing) {
            $return = $reflection->getMethod($narrowing)->getReturnType();

            self::assertNotNull($return);
            self::assertSame(Scope::class, (string) $return);
        }
    }
}
