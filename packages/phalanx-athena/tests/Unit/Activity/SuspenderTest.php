<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Activity;

use Phalanx\Athena\Activity\GrantMonitor;
use Phalanx\Athena\Activity\Suspender;
use Phalanx\Athena\Effect\Context;
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
use Phalanx\Athena\Stream\CueEmitter;
use Phalanx\Athena\Testing\ScopeStub;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn\Outcome as TurnOutcome;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use Phalanx\Panoply\Cue;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SuspenderTest extends TestCase
{
    #[Test]
    public function suspenderPersistsStateAwaitGrantAndRedispatches(): void
    {
        $grant = self::grant();
        $store = new SuspenderSpyStore();
        $monitor = new InjectableGrantMonitor($grant);
        $registry = new ToolRegistry();
        $registry->register('write_file', SuspenderEchoTool::class);
        $dispatcher = self::dispatcher($registry, new SuspenderFixedGrantStore($grant));

        $suspender = new Suspender($store, $monitor);
        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();
        $request = self::request();

        $result = $suspender($scope, 'act_1', Log::from([]), $request, $dispatcher, $emitter);

        self::assertSame(TurnOutcome::Continue, $result->turnOutcome);
        self::assertTrue($store->suspended);
        self::assertSame('act_1', $store->suspendedActivityId);
        self::assertSame($request, $store->suspendedEffect);
    }

    #[Test]
    public function suspenderPropagatesGrantMonitorException(): void
    {
        $store = new SuspenderSpyStore();
        $monitor = new InjectableGrantMonitor(null, new \RuntimeException('grant check failed'));
        $dispatcher = self::dispatcher(new ToolRegistry(), new SuspenderFixedGrantStore(null));

        $suspender = new Suspender($store, $monitor);
        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('grant check failed');

        $suspender($scope, 'act_1', Log::from([]), self::request(), $dispatcher, $emitter);
    }

    #[Test]
    public function suspenderPropagatesCancellation(): void
    {
        $store = new SuspenderSpyStore();
        $monitor = new InjectableGrantMonitor(null, new Cancelled('cancelled'));
        $dispatcher = self::dispatcher(new ToolRegistry(), new SuspenderFixedGrantStore(null));

        $suspender = new Suspender($store, $monitor);
        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();

        $this->expectException(Cancelled::class);

        $suspender($scope, 'act_1', Log::from([]), self::request(), $dispatcher, $emitter);
    }

    #[Test]
    public function suspenderForwardsLogAndEffectToStore(): void
    {
        $grant = self::grant();
        $store = new SuspenderSpyStore();
        $monitor = new InjectableGrantMonitor($grant);
        $registry = new ToolRegistry();
        $registry->register('write_file', SuspenderEchoTool::class);
        $dispatcher = self::dispatcher($registry, new SuspenderFixedGrantStore($grant));

        $suspender = new Suspender($store, $monitor);
        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();
        $log = Log::from([]);
        $request = self::request();

        $suspender($scope, 'act_1', $log, $request, $dispatcher, $emitter);

        self::assertSame($log, $store->suspendedLog);
    }

    #[Test]
    public function grantMonitorReceivesAgentIdKindAndArguments(): void
    {
        $grant = self::grant();
        $monitor = new SpyGrantMonitor($grant);
        $registry = new ToolRegistry();
        $registry->register('write_file', SuspenderEchoTool::class);
        $dispatcher = self::dispatcher($registry, new SuspenderFixedGrantStore($grant));

        $suspender = new Suspender(new SuspenderSpyStore(), $monitor);
        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();
        $request = self::request();

        $suspender($scope, 'act_1', Log::from([]), $request, $dispatcher, $emitter);

        self::assertTrue($monitor->wasCalled);
        self::assertSame('agent_1', $monitor->capturedSubject);
        self::assertSame(Kind::FileWrite, $monitor->capturedKind);
        self::assertSame([], $monitor->capturedArguments);
    }

    private static function dispatcher(ToolRegistry $registry, GrantStore $grantStore): Dispatcher
    {
        return new Dispatcher(
            authorizer: new Authorizer(),
            scorer: new Scorer(),
            grantStore: $grantStore,
            toolRegistry: $registry,
            mcpRegistry: new McpRegistry(),
        );
    }

    private static function grant(): Grant
    {
        return Grant::of(
            id: 'grant_1',
            subject: 'agent_1',
            allowedEffects: [Kind::FileWrite],
            scope: 'session',
            hazardCeiling: Hazard::Critical,
        );
    }

    private static function request(): Requested
    {
        return new Requested(
            id: 'cue_1',
            sequence: 1,
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'agent_1',
            at: new \DateTimeImmutable(),
            effectId: 'write_file',
            kind: Kind::FileWrite,
            summary: 'write a file',
            requiresApproval: true,
        );
    }
}

final class ArrayCueEmitter implements CueEmitter
{
    /** @var list<Cue> */
    public array $cues = [];

    public function emit(Cue $cue): void
    {
        $this->cues[] = $cue;
    }
}

final class SuspenderSpyStore implements ExecutionStore
{
    public bool $suspended = false;
    public ?string $suspendedActivityId = null;
    public ?Log $suspendedLog = null;
    public ?Requested $suspendedEffect = null;

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
        $this->suspendedLog = $log;
        $this->suspendedEffect = $pendingEffect;
    }

    public function loadSuspended(TaskScope $scope, string $activityId): ?SuspendedState
    {
        return null;
    }
}

final class InjectableGrantMonitor extends GrantMonitor
{
    public function __construct(
        private readonly ?Grant $grant,
        private readonly ?\Throwable $error = null,
    ) {
        parent::__construct(
            new SuspenderNullConnection(),
            new SuspenderNullGrantStore(),
        );
    }

    #[\Override]
    public function __invoke(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): Grant
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        /** @var Grant */
        return $this->grant;
    }
}

final class SuspenderEchoTool implements Tool
{
    public function __invoke(TaskScope $scope, Context $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: 'written');
    }
}

final class SuspenderFixedGrantStore implements GrantStore
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

final class SpyGrantMonitor extends GrantMonitor
{
    public bool $wasCalled = false;
    public ?string $capturedSubject = null;
    public ?Kind $capturedKind = null;
    /** @var array<string, mixed>|null */
    public ?array $capturedArguments = null;

    public function __construct(private readonly ?Grant $grant)
    {
        parent::__construct(new SuspenderNullConnection(), new SuspenderNullGrantStore());
    }

    #[\Override]
    public function __invoke(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): Grant
    {
        $this->wasCalled = true;
        $this->capturedSubject = $subject;
        $this->capturedKind = $kind;
        $this->capturedArguments = $arguments;

        /** @var Grant */
        return $this->grant;
    }
}

final class SuspenderNullConnection implements \Phalanx\Surreal\SurrealLiveConnection
{
    public bool $isOpen { get => false; }

    public function request(string $method, array $params = []): mixed
    {
        return null;
    }

    public function subscribe(string $queryId, \Phalanx\Styx\Channel $channel): void
    {
    }

    public function unsubscribe(string $queryId): void
    {
    }

    public function close(): void
    {
    }
}

final class SuspenderNullGrantStore implements GrantStore
{
    public function find(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): ?Grant
    {
        return null;
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
