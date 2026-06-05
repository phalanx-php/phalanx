<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Integration;

use Phalanx\Agent\Activity;
use Phalanx\Agent\Activity\GrantMonitor;
use Phalanx\Agent\Activity\Suspender;
use Phalanx\Agent\Effect\Context as EffectContext;
use Phalanx\Agent\Effect\Dispatcher;
use Phalanx\Agent\Effect\Outcome as EffectOutcome;
use Phalanx\Agent\Effect\Resolution;
use Phalanx\Agent\Grant\Store as GrantStore;
use Phalanx\Agent\Mcp\McpRegistry;
use Phalanx\Agent\Persistence\ActivityRecord;
use Phalanx\Agent\Persistence\EffectLogRecord;
use Phalanx\Agent\Persistence\ExecutionStore;
use Phalanx\Agent\Persistence\InvocationRecord;
use Phalanx\Agent\Persistence\PromptHashRecord;
use Phalanx\Agent\Persistence\SuspendedState;
use Phalanx\Agent\Tests\Fixtures\TestAgent;
use Phalanx\Agent\Tool\Tool;
use Phalanx\Agent\Tool\ToolRegistry;
use Phalanx\Agent\Turn\DefaultBuilder;
use Phalanx\Agent\Turn\Loop;
use Phalanx\Agent\Turn\Outcome;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Effect\Authorizer as AuthorizerContract;
use Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer;
use Phalanx\AiProviders\Effect\Decision;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer;
use Phalanx\AiProviders\Provider\Fake\Provider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Stream\Channel;
use Phalanx\SurrealDb\SurrealDbLiveConnection;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SuspensionFlowTest extends PhalanxTestCase
{
    #[Test]
    public function pausedEffectWithSuspenderResumesAndCompletesActivity(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $grant = Grant::of(
            id: 'grant_1',
            subject: 'agent_1',
            allowedEffects: [Kind::FileWrite],
            scope: 'session',
            hazardCeiling: Hazard::Critical,
        );

        $provider = new Provider([
            new Requested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: null,
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'write_file',
                kind: Kind::FileWrite,
                summary: 'write a file',
                requiresApproval: true,
            ),
            new TokenStop('cue_2', 2, 'act_1', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($provider, $grant): Activity\Result {
            $store = new FlowSpyStore();
            $registry = new ToolRegistry();
            $registry->register('write_file', FlowEchoTool::class);

            $grantStore = new FlowGrantStore($grant);
            $authorizer = new FlowOnceAuthorizer();
            $dispatcher = new Dispatcher(
                authorizer: $authorizer,
                scorer: new Scorer(),
                grantStore: $grantStore,
                toolRegistry: $registry,
                mcpRegistry: new McpRegistry(),
            );

            $monitor = new FlowImmediateMonitor($grant, $grantStore);
            $suspender = new Suspender($store, $monitor);
            $loop = new Loop(new DefaultBuilder(), $provider, suspender: $suspender, dispatcher: $dispatcher);

            return $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 2));
        });

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function loopWithoutDispatcherYieldsWaitingForApprovalForRequiresApprovalEffect(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');

        $provider = new Provider([
            new Requested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: null,
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'write_file',
                kind: Kind::FileWrite,
                summary: 'write a file',
                requiresApproval: true,
            ),
        ], Capabilities::empty());

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($provider): Activity\Result {
            $loop = new Loop(new DefaultBuilder(), $provider);

            return $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 1));
        });

        self::assertSame(Activity\State::Suspended, $result->state);
        self::assertSame(Outcome::WaitingForApproval, $result->outcome);
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function suspenderPersistsStateBeforeWaitingForGrant(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $grant = Grant::of(
            id: 'grant_1',
            subject: 'agent_1',
            allowedEffects: [Kind::FileWrite],
            scope: 'session',
            hazardCeiling: Hazard::Critical,
        );

        $store = new FlowSpyStore();
        $provider = new Provider([
            new Requested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: null,
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'write_file',
                kind: Kind::FileWrite,
                summary: 'write a file',
                requiresApproval: true,
            ),
        ], Capabilities::empty());

        $this->scope->run(static function (ExecutionScope $scope) use ($provider, $grant, $store): void {
            $registry = new ToolRegistry();
            $registry->register('write_file', FlowEchoTool::class);

            $grantStore = new FlowGrantStore($grant);
            $dispatcher = new Dispatcher(
                authorizer: new FlowPausingAuthorizer(),
                scorer: new Scorer(),
                grantStore: $grantStore,
                toolRegistry: $registry,
                mcpRegistry: new McpRegistry(),
            );

            $monitor = new FlowImmediateMonitor($grant, $grantStore);
            $suspender = new Suspender($store, $monitor);
            $loop = new Loop(new DefaultBuilder(), $provider, suspender: $suspender, dispatcher: $dispatcher);

            $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 2));
        });

        self::assertTrue($store->suspended);
        self::assertSame('act_1', $store->suspendedActivityId);
        $this->scope->expect->runtime()->clean();
    }
}

final class FlowSpyStore implements ExecutionStore
{
    public bool $suspended = false;
    public ?string $suspendedActivityId = null;

    public function saveActivity(TaskScope $scope, ActivityRecord $record): void
    {
    }

    public function findActivity(TaskScope $scope, string $activityId): ?ActivityRecord
    {
        return null;
    }

    public function saveInvocation(TaskScope $scope, InvocationRecord $record): void
    {
    }

    public function logEffect(TaskScope $scope, EffectLogRecord $record): void
    {
    }

    public function savePromptHash(TaskScope $scope, PromptHashRecord $record): void
    {
    }

    public function findPromptHash(TaskScope $scope, string $hash): ?PromptHashRecord
    {
        return null;
    }

    public function suspendActivity(TaskScope $scope, string $activityId, Log $log, Requested $pendingEffect): void
    {
        $this->suspended = true;
        $this->suspendedActivityId = $activityId;
    }

    public function loadSuspended(TaskScope $scope, string $activityId): ?SuspendedState
    {
        return null;
    }
}

final class FlowEchoTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: 'written');
    }
}

final class FlowGrantStore implements GrantStore
{
    public function __construct(private readonly ?Grant $grant)
    {
    }

    public function find(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): ?Grant
    {
        return $this->grant;
    }

    public function remember(TaskScope $scope, Grant $grant): void
    {
    }

    public function consume(TaskScope $scope, Grant $grant): void
    {
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
    }
}

final class FlowImmediateMonitor extends GrantMonitor
{
    public function __construct(
        private readonly Grant $grant,
        GrantStore $grantStore,
    ) {
        parent::__construct(new FlowNullConnection(), $grantStore);
    }

    #[\Override]
    public function __invoke(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): Grant
    {
        return $this->grant;
    }
}

final class FlowNullConnection implements SurrealDbLiveConnection
{
    public bool $isOpen { get => false; }

    public function request(string $method, array $params = []): mixed
    {
        return null;
    }

    public function subscribe(string $queryId, Channel $channel): void
    {
    }

    public function unsubscribe(string $queryId): void
    {
    }

    public function close(): void
    {
    }
}

final class FlowPausingAuthorizer implements AuthorizerContract
{
    public function evaluate(\Phalanx\AiProviders\Effect $effect, ?Grant $grant = null): Decision
    {
        return Decision::paused('Human approval required');
    }
}

final class FlowOnceAuthorizer implements AuthorizerContract
{
    private int $calls = 0;

    private Authorizer $real;

    public function __construct()
    {
        $this->real = new Authorizer();
    }

    public function evaluate(\Phalanx\AiProviders\Effect $effect, ?Grant $grant = null): Decision
    {
        $this->calls++;

        if ($this->calls === 1) {
            return Decision::paused('Approval required on first call');
        }

        return $this->real->evaluate($effect, $grant);
    }
}
