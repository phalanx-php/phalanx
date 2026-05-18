<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Activity\GrantMonitor;
use Phalanx\Athena\Activity\Suspender;
use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Persistence\ActivityRecord;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Athena\Persistence\ExecutionStore;
use Phalanx\Athena\Persistence\InvocationRecord;
use Phalanx\Athena\Persistence\PromptHashRecord;
use Phalanx\Athena\Persistence\SuspendedState;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Authorizer as AuthorizerContract;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use Phalanx\Panoply\Provider\Fake\Provider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Styx\Channel;
use Phalanx\Surreal\SurrealLiveConnection;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class EffectApprovalFlowTest extends PhalanxTestCase
{
    #[Test]
    public function pausedEffectFlowsThroughSuspenderAndResumesWithGrant(): void
    {
        $at    = new \DateTimeImmutable('2026-05-18T10:00:00Z');
        $grant = Grant::of(
            id: 'grant_approval',
            subject: 'athena-test-agent',
            allowedEffects: [Kind::FileWrite],
            scope: 'session',
            hazardCeiling: Hazard::Critical,
        );

        $provider = new Provider([
            new Requested(
                id: 'cue_a1',
                sequence: 1,
                activityId: 'act_approval',
                invocationId: null,
                agentId: 'athena-test-agent',
                at: $at,
                effectId: 'write_approved',
                kind: Kind::FileWrite,
                summary: 'write with approval',
                requiresApproval: true,
            ),
            new TokenStop('cue_a2', 2, 'act_approval', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $spyStore = new ApprovalSpyStore();

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($provider, $grant, $spyStore): Activity\Result {
                $registry = new ToolRegistry();
                $registry->register('write_approved', ApprovalWriteTool::class);

                $grantStore  = new ApprovalGrantStore($grant);
                $authorizer  = new ApprovalOnceAuthorizer();
                $dispatcher  = new Dispatcher(
                    authorizer: $authorizer,
                    scorer: new Scorer(),
                    grantStore: $grantStore,
                    toolRegistry: $registry,
                    mcpRegistry: new McpRegistry(),
                );

                $monitor   = new ApprovalImmediateMonitor($grant, $grantStore);
                $suspender = new Suspender($spyStore, $monitor);
                $loop      = new Loop(new DefaultBuilder(), $provider, suspender: $suspender, dispatcher: $dispatcher);

                return $loop($scope, new TestAgent(), new Activity\Config('act_approval', Context::new(), 2));
            },
        );

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);
        self::assertTrue($spyStore->suspended, 'State must be persisted during suspension');
        self::assertSame('act_approval', $spyStore->suspendedActivityId);

        $types = array_map(static fn($cue): string => $cue->type, $result->stream->toArray());
        self::assertContains('cue.effect.paused', $types);
        self::assertContains('cue.effect.authorized', $types);
        self::assertContains('cue.effect.executed', $types);

        $this->scope->expect->runtime()->clean();
    }
}

final class ApprovalSpyStore implements ExecutionStore
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
        $this->suspended           = true;
        $this->suspendedActivityId = $activityId;
    }

    public function loadSuspended(TaskScope $scope, string $activityId): ?SuspendedState
    {
        return null;
    }
}

final class ApprovalWriteTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: 'written');
    }
}

final class ApprovalGrantStore implements GrantStore
{
    public function __construct(private(set) ?Grant $grant)
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

final class ApprovalOnceAuthorizer implements AuthorizerContract
{
    private int $calls = 0;

    public function evaluate(Effect $effect, ?Grant $grant = null): Decision
    {
        $this->calls++;

        if ($this->calls === 1) {
            return Decision::paused('Approval required');
        }

        if ($grant !== null && $grant->permits($effect->kind)) {
            return Decision::granted($grant->id);
        }

        return Decision::denied('no-grant');
    }
}

final class ApprovalImmediateMonitor extends GrantMonitor
{
    public function __construct(
        private(set) Grant $grant,
        GrantStore $grantStore,
    ) {
        parent::__construct(new ApprovalNullConnection(), $grantStore);
    }

    #[\Override]
    public function __invoke(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): Grant
    {
        return $this->grant;
    }
}

final class ApprovalNullConnection implements SurrealLiveConnection
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
