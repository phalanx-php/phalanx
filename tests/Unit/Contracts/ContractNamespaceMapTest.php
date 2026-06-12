<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Contracts;

use Phalanx\Engine\Engine;
use Phalanx\Err\Err;
use Phalanx\Err\Severity;
use Phalanx\Invocation\Caps;
use Phalanx\Invocation\Executable;
use Phalanx\Invocation\InvocationCtx;
use Phalanx\Phalanx;
use Phalanx\Schema\SchemaProjectable;
use Phalanx\Scope\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContractNamespaceMapTest extends TestCase
{
    #[Test]
    public function v2ContractMarkerInterfacesAreAutoloadable(): void
    {
        foreach ($this->markerContracts() as $contract) {
            self::assertTrue(interface_exists($contract), "{$contract} should be an autoloadable interface.");
        }
    }

    #[Test]
    public function v2ContractMarkerInterfacesDoNotDeclareRuntimeBehaviorYet(): void
    {
        foreach ($this->markerContracts() as $contract) {
            $reflection = new ReflectionClass($contract);

            self::assertSame([], $reflection->getMethods(), "{$contract} must stay behavior-free in PV2-A.02.");
        }
    }

    #[Test]
    public function severityDefinesTheExpectedFailureRoutingVocabulary(): void
    {
        self::assertSame(
            ['Expected', 'Transient', 'Degraded', 'Fatal'],
            array_column(Severity::cases(), 'name'),
        );
    }

    #[Test]
    public function packageEntrypointRemainsBootstrapMetadataOnly(): void
    {
        $reflection = new ReflectionClass(Phalanx::class);

        self::assertSame(['bootstrapContract'], array_map(
            static fn ($method): string => $method->getName(),
            $reflection->getMethods(),
        ));
    }

    /** @return list<class-string> */
    private function markerContracts(): array
    {
        return [
            Caps::class,
            Engine::class,
            Err::class,
            Executable::class,
            InvocationCtx::class,
            SchemaProjectable::class,
            Scope::class,
        ];
    }
}
