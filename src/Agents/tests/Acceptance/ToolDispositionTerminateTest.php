<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Acceptance;

use Phalanx\Agents\Activity;
use Phalanx\Agents\Effect\Dispatcher;
use Phalanx\Agents\Grant\MemoryGrantStore;
use Phalanx\Agents\Mcp\McpRegistry;
use Phalanx\Agents\Tests\Fixtures\TestAgent;
use Phalanx\Agents\Tool\ToolRegistry;
use Phalanx\Agents\Turn\DefaultBuilder;
use Phalanx\Agents\Turn\Loop;
use Phalanx\Agents\Turn\Outcome;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer;
use Phalanx\AiProviders\Provider\Fake\Provider;
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
                agentId: 'agent-test-agent',
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
                    subject: 'agent-test-agent',
                    allowedEffects: [Kind::Custom],
                    grantScope: 'session',
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
