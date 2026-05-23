<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Router;

use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Athena\Testing\ScopeStub;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SingleProviderRouterTest extends TestCase
{
    #[Test]
    public function returnsSameProviderForEveryInvocation(): void
    {
        $provider = new class implements Provider {
            public function perform(Invocation $invocation, Runtime $runtime): Stream
            {
                return Stream::from([]);
            }

            public function capabilities(): Capabilities
            {
                return Capabilities::of(Capability::Reasoning);
            }
        };

        $router = new SingleProviderRouter($provider);
        $scope = new ScopeStub();

        $agent = new class implements Agent {
            public string $id { get => 'leonidas'; }
            public string $name { get => 'Leonidas'; }
            public string $purpose { get => 'Hold the pass.'; }
            public Output $output { get => Output::text(); }
            public Context $context { get => Context::new(); }
            public Effects $effects { get => Effects::allow(Kind::FileRead); }
            public ProviderNeeds $provider { get => ProviderNeeds::new(); }
            public Capabilities $capabilities { get => Capabilities::of(Capability::Reasoning); }
            public TransportNeeds $transport { get => TransportNeeds::new(); }
        };

        $invocationA = Invocation::of(
            id: 'inv-a',
            agentId: 'leonidas',
            activityId: 'act-1',
            contextHash: 'hash-a',
            instructions: 'First call.',
            output: Output::text(),
            effects: Effects::allow(Kind::FileRead),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
        );

        $invocationB = Invocation::of(
            id: 'inv-b',
            agentId: 'leonidas',
            activityId: 'act-1',
            contextHash: 'hash-b',
            instructions: 'Second call.',
            output: Output::text(),
            effects: Effects::allow(Kind::FileRead),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
        );

        $resultA = $router->route($scope, $agent, $invocationA);
        $resultB = $router->route($scope, $agent, $invocationB);

        self::assertSame($provider, $resultA);
        self::assertSame($provider, $resultB);
    }
}
