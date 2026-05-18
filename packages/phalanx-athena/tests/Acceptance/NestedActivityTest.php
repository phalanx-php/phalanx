<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Grant\MemoryGrantStore;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use Phalanx\Panoply\Provider\Fake\Provider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NestedActivityTest extends PhalanxTestCase
{
    #[Test]
    public function toolThatRunsChildLoopCompletesAndBothLoopsYieldCompletedState(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $parentProvider = new Provider([
            new Requested(
                id: 'cue_p1',
                sequence: 1,
                activityId: 'act_parent',
                invocationId: null,
                agentId: 'athena-test-agent',
                at: $at,
                effectId: 'child_agent_tool',
                kind: Kind::Custom,
                summary: 'invoke child agent',
            ),
            new TokenStop('cue_p2', 2, 'act_parent', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $childProvider = new Provider([
            new TokenDelta('cue_c1', 10, 'act_child', null, 'athena-test-agent', $at, 'child result'),
            new TokenStop('cue_c2', 11, 'act_child', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($parentProvider, $childProvider): Activity\Result {
                ChildAgentTool::$childProvider = $childProvider;

                $registry = new ToolRegistry();
                $registry->register('child_agent_tool', ChildAgentTool::class);

                $grantStore = new MemoryGrantStore();
                $grantStore->remember($scope, Grant::of(
                    id: 'grant_nested',
                    subject: 'athena-test-agent',
                    allowedEffects: [Kind::Custom],
                    scope: 'session',
                    hazardCeiling: Hazard::Critical,
                ));

                $dispatcher = new Dispatcher(
                    authorizer: new Authorizer(),
                    scorer: new Scorer(),
                    grantStore: $grantStore,
                    toolRegistry: $registry,
                    mcpRegistry: new McpRegistry(),
                );

                $loop = new Loop(new DefaultBuilder(), $parentProvider, dispatcher: $dispatcher);

                return $loop($scope, new TestAgent(), new Activity\Config('act_parent', Context::new(), 2));
            },
        );

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);

        $this->scope->expect->runtime()->clean();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ChildAgentTool::$childProvider = null;
        parent::tearDown();
    }
}

final class ChildAgentTool implements Tool
{
    public static ?\Phalanx\Panoply\Provider $childProvider = null;

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        $provider  = self::$childProvider ?? throw new \RuntimeException('childProvider not set');
        $childLoop = new Loop(new DefaultBuilder(), $provider);
        $result    = $childLoop($scope, new TestAgent(), new Activity\Config('act_child', Context::new(), 1));

        return EffectOutcome::routed(Resolution::LocalTool, data: $result->state->value);
    }
}
