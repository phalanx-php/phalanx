<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Acceptance;

use Phalanx\Agent\Activity;
use Phalanx\Agent\Activity\Activity as ActivityRunner;
use Phalanx\Agent\Effect\Context as EffectContext;
use Phalanx\Agent\Effect\Dispatcher;
use Phalanx\Agent\Effect\Outcome as EffectOutcome;
use Phalanx\Agent\Effect\Resolution;
use Phalanx\Agent\Grant\MemoryGrantStore;
use Phalanx\Agent\Mcp\McpRegistry;
use Phalanx\Agent\Tests\Fixtures\TestAgent;
use Phalanx\Agent\Tool\Tool;
use Phalanx\Agent\Tool\ToolRegistry;
use Phalanx\Agent\Turn\DefaultBuilder;
use Phalanx\Agent\Turn\Loop;
use Phalanx\Agent\Turn\Outcome;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Cue\Effect\Requested;
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
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'echo_tool',
                kind: Kind::FileRead,
                summary: 'read a file',
            ),
            new TokenStop('cue_2', 2, 'act_lifecycle', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        [$types, $state, $outcome] = $this->scope->run(static function (ExecutionScope $scope) use ($provider): array {
            $registry = new ToolRegistry();
            $registry->register('echo_tool', AcceptanceEchoTool::class);

            $grantStore = new MemoryGrantStore();
            $grantStore->remember($scope, Grant::of(
                id: 'grant_lifecycle',
                subject: 'agent-test-agent',
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

            $result = $activity($scope, new TestAgent(), new Activity\Config('act_lifecycle', Context::new(), 2));
            $types = array_map(static fn($cue): string => $cue->type, $result->stream->toArray());

            return [$types, $result->state, $result->outcome];
        });

        self::assertSame(Activity\State::Completed, $state);
        self::assertSame(Outcome::Complete, $outcome);

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
