<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Swarm\Daemon8SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\Iris\HttpClient;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

class Daemon8SwarmBusTest extends PhalanxTestCase
{
    #[Test]
    public function testItParsesCurrentDaemon8CustomChannelShape(): void
    {
        $event = Daemon8SwarmBus::eventFromObservation([
            'kind' => [
                'type' => 'custom',
                'channel' => 'swarm_message',
            ],
            'data' => [
                'schema' => 'phalanx.swarm.v1',
                'event_id' => 'ev_test',
                'trace_id' => 'trace_1',
                'causation_id' => null,
                'workspace' => 'rtntv',
                'session' => 'swarm-test',
                'from' => 'VISION',
                'addressed_to' => ['ADMIN'],
                'kind' => 'planning_proposal',
                'payload' => ['text' => 'Use current Daemon8 shape.'],
            ],
        ], [
            'workspace' => 'rtntv',
            'kinds' => SwarmEventKind::PlanningProposal,
            'addressed_to' => 'ADMIN',
        ]);

        $this->assertInstanceOf(SwarmEvent::class, $event);
        $this->assertSame('VISION', $event->from);
        $this->assertSame(SwarmEventKind::PlanningProposal, $event->kind);
        $this->assertSame('trace_1', $event->traceId);
    }

    #[Group('live')]
    #[Test]
    public function testItCanEmitToDaemon8(): void
    {
        $config = new SwarmConfig(
            workspace: 'test-ws',
            session: 'test-session',
            daemon8Url: 'http://localhost:9077'
        );
        $bus = new Daemon8SwarmBus($config, new HttpClient());

        $event = new SwarmEvent(
            from: 'TEST_AGENT',
            kind: SwarmEventKind::Online,
            workspace: 'test-ws',
            session: 'test-session'
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($bus, $event): void {
            $bus->emit($scope, $event);
        });

        $this->assertTrue(true, 'Emit did not crash');
    }
}
