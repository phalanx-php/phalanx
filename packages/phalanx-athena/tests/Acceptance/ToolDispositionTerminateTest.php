<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Grant\MemoryGrantStore;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use Phalanx\Panoply\Provider\Fake\Provider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ToolDispositionTerminateTest extends PhalanxTestCase
{
    #[Test]
    public function haltBuiltInStopsLoopAndResolvesToCompletedState(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $provider = new Provider([
            new Requested(
                id: 'cue_t1',
                sequence: 1,
                activityId: 'act_terminate',
                invocationId: null,
                agentId: 'athena-test-agent',
                at: $at,
                effectId: 'halt',
                kind: Kind::Custom,
                summary: 'terminate loop',
            ),
        ], Capabilities::empty());

        $this->scope->run(
            static function (ExecutionScope $scope) use ($provider): void {
                $grantStore = new MemoryGrantStore();
                $grantStore->remember($scope, Grant::of(
                    id: 'grant_halt',
                    subject: 'athena-test-agent',
                    allowedEffects: [Kind::Custom],
                    scope: 'session',
                    hazardCeiling: Hazard::Critical,
                ));

                $dispatcher = new Dispatcher(
                    authorizer: new Authorizer(),
                    scorer: new Scorer(),
                    grantStore: $grantStore,
                    toolRegistry: new ToolRegistry(),
                    mcpRegistry: new McpRegistry(),
                );

                $loop = new Loop(new DefaultBuilder(), $provider, dispatcher: $dispatcher);

                $result = $loop($scope, new TestAgent(), new Activity\Config('act_terminate', Context::new(), 1));

                $cues = $result->stream->toArray();
                $types = array_map(static fn($cue): string => $cue->type, $cues);

                self::assertSame(Activity\State::Completed, $result->state);
                self::assertSame(Outcome::Complete, $result->outcome);
                self::assertSame(1, $result->invocations);
                self::assertContains('cue.effect.requested', $types);
            },
        );

        $this->scope->expect->runtime()->clean();
    }
}
