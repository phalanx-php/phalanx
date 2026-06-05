<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Acceptance;

use Phalanx\Agents\Activity;
use Phalanx\Agents\Effect\Context as EffectContext;
use Phalanx\Agents\Effect\Dispatcher;
use Phalanx\Agents\Effect\Outcome as EffectOutcome;
use Phalanx\Agents\Effect\Resolution;
use Phalanx\Agents\Grant\MemoryGrantStore;
use Phalanx\Agents\Mcp\McpRegistry;
use Phalanx\Agents\Tests\Fixtures\TestAgent;
use Phalanx\Agents\Tool\Tool;
use Phalanx\Agents\Tool\ToolRegistry;
use Phalanx\Agents\Turn\DefaultBuilder;
use Phalanx\Agents\Turn\Loop;
use Phalanx\Agents\Turn\Outcome;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer;
use Phalanx\AiProviders\Provider\Fake\Provider;
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
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'child_agent_tool',
                kind: Kind::Custom,
                summary: 'invoke child agent',
            ),
            new TokenStop('cue_p2', 2, 'act_parent', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $childProvider = new Provider([
            new TokenDelta('cue_c1', 10, 'act_child', null, 'agent-test-agent', $at, 'child result'),
            new TokenStop('cue_c2', 11, 'act_child', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($parentProvider, $childProvider): Activity\Result {
                ChildAgentTool::$childProvider = $childProvider;

                $registry = new ToolRegistry();
                $registry->register('child_agent_tool', ChildAgentTool::class);

                $grantStore = new MemoryGrantStore();
                $grantStore->remember($scope, Grant::of(
                    id: 'grant_nested',
                    subject: 'agent-test-agent',
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
    public static ?\Phalanx\AiProviders\Provider $childProvider = null;

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        $provider = self::$childProvider ?? throw new \RuntimeException('childProvider not set');
        $childLoop = new Loop(new DefaultBuilder(), $provider);
        $result = $childLoop($scope, new TestAgent(), new Activity\Config('act_child', Context::new(), 1));

        return EffectOutcome::routed(Resolution::LocalTool, data: $result->state->value);
    }
}
