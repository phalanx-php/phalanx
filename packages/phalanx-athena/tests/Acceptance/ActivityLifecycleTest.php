<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Activity\Activity as ActivityRunner;
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

final class ActivityLifecycleTest extends PhalanxTestCase
{
    #[Test]
    public function activityWithFakeProviderAndOneToolCompletesWithLifecycleCues(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $provider = new Provider([
            new Requested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_lifecycle',
                invocationId: null,
                agentId: 'athena-test-agent',
                at: $at,
                effectId: 'echo_tool',
                kind: Kind::FileRead,
                summary: 'read a file',
            ),
            new TokenStop('cue_2', 2, 'act_lifecycle', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($provider): Activity\Result {
            $registry = new ToolRegistry();
            $registry->register('echo_tool', AcceptanceEchoTool::class);

            $grantStore = new MemoryGrantStore();
            $grantStore->remember($scope, Grant::of(
                id: 'grant_lifecycle',
                subject: 'athena-test-agent',
                allowedEffects: [Kind::FileRead],
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

            $loop = new Loop(new DefaultBuilder(), $provider, dispatcher: $dispatcher);
            $activity = new ActivityRunner($loop);

            return $activity($scope, new TestAgent(), new Activity\Config('act_lifecycle', Context::new(), 2));
        });

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);

        $types = array_map(static fn($cue): string => $cue->type, $result->stream->toArray());

        self::assertContains('cue.activity.started', $types);
        self::assertContains('cue.effect.authorized', $types);
        self::assertContains('cue.effect.executed', $types);
        self::assertContains('cue.activity.completed', $types);

        $this->scope->expect->runtime()->clean();
    }
}

final class AcceptanceEchoTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: 'ok');
    }
}
