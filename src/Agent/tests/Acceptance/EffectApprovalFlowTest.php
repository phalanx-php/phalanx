<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Acceptance;

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
use Phalanx\AiProviders\Effect;
use Phalanx\AiProviders\Effect\Authorizer as AuthorizerContract;
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

final class EffectApprovalFlowTest extends PhalanxTestCase
{
    #[Test]
    public function pausedEffectFlowsThroughSuspenderAndResumesWithGrant(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');
        $grant = Grant::of(
            id: 'grant_approval',
            subject: 'agent-test-agent',
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
                agentId: 'agent-test-agent',
                at: $at,
                effectId: 'write_approved',
                kind: Kind::FileWrite,
                summary: 'write with approval',
                requiresApproval: true,
            ),
            new TokenStop('cue_a2', 2, 'act_approval', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $spyStore = new ApprovalSpyStore();

        $this->scope->run(
            static function (ExecutionScope $scope) use ($provider, $grant, $spyStore): void {
                $registry = new ToolRegistry();
                $registry->register('write_approved', ApprovalWriteTool::class);

                $grantStore = new ApprovalGrantStore($grant);
                $authorizer = new ApprovalOnceAuthorizer();
                $dispatcher = new Dispatcher(
                    authorizer: $authorizer,
                    scorer: new Scorer(),
                    grantStore: $grantStore,
                    toolRegistry: $registry,
                    mcpRegistry: new McpRegistry(),
                );

                $monitor = new ApprovalImmediateMonitor($grant, $grantStore);
                $suspender = new Suspender($spyStore, $monitor);
                $loop = new Loop(new DefaultBuilder(), $provider, suspender: $suspender, dispatcher: $dispatcher);

                $result = $loop($scope, new TestAgent(), new Activity\Config('act_approval', Context::new(), 2));

                $cues = $result->stream->toArray();
                $types = array_map(static fn($cue): string => $cue->type, $cues);

                self::assertSame(Activity\State::Completed, $result->state);
                self::assertSame(Outcome::Complete, $result->outcome);
                self::assertContains('cue.effect.paused', $types);
                self::assertContains('cue.effect.authorized', $types);
                self::assertContains('cue.effect.executed', $types);
            },
        );

        self::assertTrue($spyStore->suspended, 'State must be persisted during suspension');
        self::assertSame('act_approval', $spyStore->suspendedActivityId);

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
        $this->suspended = true;
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

final class ApprovalNullConnection implements SurrealDbLiveConnection
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
