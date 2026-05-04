<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Swarm\Daemon8SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class Daemon8SwarmBusTest extends TestCase
{
    public function test_it_parses_current_daemon8_custom_channel_shape(): void
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
    public function test_it_can_emit_to_daemon8(): void
    {
        $config = new SwarmConfig(
            workspace: 'test-ws',
            session: 'test-session',
            daemon8Url: 'http://localhost:9077'
        );
        $bus = new Daemon8SwarmBus($config);

        $event = new SwarmEvent(
            from: 'TEST_AGENT',
            kind: SwarmEventKind::Online,
            workspace: 'test-ws',
            session: 'test-session'
        );

        // This will attempt a real network call to localhost:9077
        $bus->emit($event);
        
        $this->assertTrue(true, 'Emit did not crash');
    }
}
