<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Effect;

use Phalanx\Athena\Effect\Context;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Effect\Outcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Exception\EffectDenied;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn;
use Phalanx\Panoply\Cue\Effect\Authorized;
use Phalanx\Panoply\Cue\Effect\Denied;
use Phalanx\Panoply\Cue\Effect\Executed;
use Phalanx\Panoply\Cue\Effect\Failed;
use Phalanx\Panoply\Cue\Effect\Paused;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    #[Test]
    public function grantedToolEmitsAuthorizedAndExecutedCues(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', EchoTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(self::grant(Kind::FileRead)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('read_file', Kind::FileRead, arguments: ['path' => '/tmp/test']);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);
        self::assertNotNull($result->effectOutcome);
        self::assertSame(Resolution::LocalTool, $result->effectOutcome->resolution);

        $cues = $stream->stream()->toArray();
        self::assertCount(2, $cues);
        self::assertInstanceOf(Authorized::class, $cues[0]);
        self::assertInstanceOf(Executed::class, $cues[1]);
    }

    #[Test]
    public function deniedEffectEmitsDeniedCue(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', EchoTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(null),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('read_file', Kind::FileRead);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Failed, $result->turnOutcome);
        self::assertInstanceOf(EffectDenied::class, $result->error);

        $cues = $stream->stream()->toArray();
        self::assertCount(1, $cues);
        self::assertInstanceOf(Denied::class, $cues[0]);
        self::assertContains('no-grant', $cues[0]->reasonCodes);
    }

    #[Test]
    public function hazardExceedingCeilingDenies(): void
    {
        $grant = self::grant(Kind::ShellExec, hazardCeiling: Hazard::Low);

        $registry = new ToolRegistry();
        $registry->register('shell_exec', EchoTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore($grant),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('shell_exec', Kind::ShellExec);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Failed, $result->turnOutcome);
        self::assertInstanceOf(EffectDenied::class, $result->error);

        $cues = $stream->stream()->toArray();
        self::assertInstanceOf(Denied::class, $cues[0]);
        self::assertContains('hazard-exceeds-ceiling', $cues[0]->reasonCodes);
    }

    #[Test]
    public function pausedEffectEmitsPausedCue(): void
    {
        $registry = new ToolRegistry();
        $registry->register('write_file', EchoTool::class);

        $dispatcher = self::dispatcher(
            authorizer: new PausingAuthorizer(),
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(self::grant(Kind::FileWrite)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('write_file', Kind::FileWrite);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::WaitingForApproval, $result->turnOutcome);
        self::assertNull($result->error);

        $cues = $stream->stream()->toArray();
        self::assertCount(1, $cues);
        self::assertInstanceOf(Paused::class, $cues[0]);
    }

    #[Test]
    public function toolExceptionEmitsAuthorizedAndFailedCues(): void
    {
        $registry = new ToolRegistry();
        $registry->register('fail_tool', FailingTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('fail_tool', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Failed, $result->turnOutcome);
        self::assertNotNull($result->error);
        self::assertSame('Tool broke', $result->error->getMessage());

        $cues = $stream->stream()->toArray();
        self::assertCount(2, $cues);
        self::assertInstanceOf(Authorized::class, $cues[0]);
        self::assertInstanceOf(Failed::class, $cues[1]);
    }

    #[Test]
    public function builtInNoopRoutesToBuiltInExecutor(): void
    {
        $dispatcher = self::dispatcher(
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('noop', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);
        self::assertSame(Resolution::BuiltIn, $result->effectOutcome->resolution);
    }

    #[Test]
    public function builtInHaltReturnsCompleteOutcome(): void
    {
        $dispatcher = self::dispatcher(
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('halt', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Complete, $result->turnOutcome);
    }

    #[Test]
    public function localToolRoutesViaToolRegistry(): void
    {
        $registry = new ToolRegistry();
        $registry->register('search', EchoTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(self::grant(Kind::CodeSearch)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('search', Kind::CodeSearch, arguments: ['query' => 'test']);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);
        self::assertSame(Resolution::LocalTool, $result->effectOutcome->resolution);
    }

    #[Test]
    public function unresolvableEffectReturnsFailed(): void
    {
        $dispatcher = self::dispatcher(
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('nonexistent_tool', Kind::Custom);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unresolvable effect: nonexistent_tool');

        $dispatcher->dispatch($scope, $request, $stream);
    }

    #[Test]
    public function cancelledExceptionPropagatesWithoutWrapping(): void
    {
        $registry = new ToolRegistry();
        $registry->register('cancel_tool', CancellingTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('cancel_tool', Kind::Custom);

        $this->expectException(\Phalanx\Cancellation\Cancelled::class);

        $dispatcher->dispatch($scope, $request, $stream);
    }

    #[Test]
    public function requiresApprovalFlagDoesNotOverrideAuthorizerDecision(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', EchoTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(self::grant(Kind::FileRead)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('read_file', Kind::FileRead, requiresApproval: true);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);

        $cues = $stream->stream()->toArray();
        self::assertInstanceOf(Authorized::class, $cues[0]);
    }

    #[Test]
    public function builtInTakesPriorityOverLocalTool(): void
    {
        $registry = new ToolRegistry();
        $registry->register('noop', EchoTool::class);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $request = self::request('noop', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $stream);

        self::assertSame(Resolution::BuiltIn, $result->effectOutcome->resolution);
    }

    private static function dispatcher(
        ?Effect\Authorizer $authorizer = null,
        ?Hazard\Scorer $scorer = null,
        ?\Phalanx\Athena\Grant\Store $grantStore = null,
        ?ToolRegistry $toolRegistry = null,
        ?McpRegistry $mcpRegistry = null,
    ): Dispatcher {
        return new Dispatcher(
            authorizer: $authorizer ?? new Authorizer(),
            scorer: $scorer ?? new Scorer(),
            grantStore: $grantStore ?? new FixedGrantStore(null),
            toolRegistry: $toolRegistry ?? new ToolRegistry(),
            mcpRegistry: $mcpRegistry ?? new McpRegistry(),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function request(
        string $effectId,
        Kind $kind,
        array $arguments = [],
        bool $requiresApproval = false,
    ): Requested {
        return new Requested(
            id: 'cue_test',
            sequence: 10,
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'agent_1',
            at: new \DateTimeImmutable(),
            effectId: $effectId,
            kind: $kind,
            summary: "Test effect: {$effectId}",
            arguments: $arguments,
            requiresApproval: $requiresApproval,
        );
    }

    private static function grant(Kind $kind, Hazard $hazardCeiling = Hazard::Critical): Grant
    {
        return Grant::of(
            id: 'grant_1',
            subject: 'agent_1',
            allowedEffects: [$kind],
            scope: 'session',
            hazardCeiling: $hazardCeiling,
        );
    }
}

// -- Fixtures ----------------------------------------------------------------

final class EchoTool implements Tool
{
    public function __invoke(TaskScope $scope, Context $ctx): Outcome
    {
        return Outcome::routed(Resolution::LocalTool, data: 'echoed');
    }
}

final class FailingTool implements Tool
{
    public function __invoke(TaskScope $scope, Context $ctx): Outcome
    {
        throw new \RuntimeException('Tool broke');
    }
}

final class FixedGrantStore implements \Phalanx\Athena\Grant\Store
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

final class CancellingTool implements Tool
{
    public function __invoke(TaskScope $scope, Context $ctx): Outcome
    {
        throw new \Phalanx\Cancellation\Cancelled('Scope cancelled');
    }
}

final class PausingAuthorizer implements Effect\Authorizer
{
    public function evaluate(Effect $effect, ?Grant $grant = null): Decision
    {
        return Decision::paused('Human approval required');
    }
}
