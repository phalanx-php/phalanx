<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit\Effect;

use Phalanx\Agent\Effect\Context;
use Phalanx\Agent\Effect\Dispatcher;
use Phalanx\Agent\Effect\Outcome;
use Phalanx\Agent\Effect\Resolution;
use Phalanx\Agent\Exception\EffectDenied;
use Phalanx\Agent\Grant\Scope as GrantScope;
use Phalanx\Agent\Grant\Store as GrantStore;
use Phalanx\Agent\Mcp\McpConnection;
use Phalanx\Agent\Mcp\McpRegistry;
use Phalanx\Agent\Mcp\McpTool;
use Phalanx\Agent\Stream\ArrayCueEmitter;
use Phalanx\Agent\Testing\ScopeStub;
use Phalanx\Agent\Tool\Tool;
use Phalanx\Agent\Tool\ToolRegistry;
use Phalanx\Agent\Turn;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\AiProviders\Cue\Effect\Authorized;
use Phalanx\AiProviders\Cue\Effect\Denied;
use Phalanx\AiProviders\Cue\Effect\Executed;
use Phalanx\AiProviders\Cue\Effect\Failed;
use Phalanx\AiProviders\Cue\Effect\Paused;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect;
use Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer;
use Phalanx\AiProviders\Effect\Decision;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Effect\Outcome as AiProvidersOutcome;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer;
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('read_file', Kind::FileRead, arguments: ['path' => '/tmp/test']);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);
        self::assertNotNull($result->effectOutcome);
        self::assertSame(Resolution::LocalTool, $result->effectOutcome->resolution);
        self::assertSame('echoed', $result->data);

        $cues = $emitter->cues;
        self::assertCount(2, $cues);
        self::assertInstanceOf(Authorized::class, $cues[0]);
        self::assertSame('read_file', $cues[0]->effectId);
        self::assertSame(11, $cues[0]->sequence);
        self::assertInstanceOf(Executed::class, $cues[1]);
        self::assertSame('read_file', $cues[1]->effectId);
        self::assertSame(12, $cues[1]->sequence);
        self::assertGreaterThanOrEqual(0, $cues[1]->durationMs);
    }

    #[Test]
    public function onceGrantIsConsumedAfterGrantedEffect(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', EchoTool::class);
        $grant = Grant::of(
            id: 'grant_once',
            subject: 'agent_1',
            allowedEffects: [Kind::FileRead],
            scope: GrantScope::Once->value,
            hazardCeiling: Hazard::Critical,
        );
        $grantStore = new FixedGrantStore($grant);

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: $grantStore,
        );

        $dispatcher->dispatch(
            new ScopeStub(),
            self::request('read_file', Kind::FileRead, arguments: ['path' => '/tmp/test']),
            new ArrayCueEmitter(),
        );

        self::assertSame([$grant], $grantStore->consumed);
    }

    #[Test]
    public function sessionGrantIsNotConsumedAfterGrantedEffect(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', EchoTool::class);
        $grantStore = new FixedGrantStore(self::grant(Kind::FileRead));

        $dispatcher = self::dispatcher(
            toolRegistry: $registry,
            grantStore: $grantStore,
        );

        $dispatcher->dispatch(
            new ScopeStub(),
            self::request('read_file', Kind::FileRead, arguments: ['path' => '/tmp/test']),
            new ArrayCueEmitter(),
        );

        self::assertSame([], $grantStore->consumed);
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('read_file', Kind::FileRead);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Failed, $result->turnOutcome);
        self::assertInstanceOf(EffectDenied::class, $result->error);

        $cues = $emitter->cues;
        self::assertCount(1, $cues);
        self::assertInstanceOf(Denied::class, $cues[0]);
        self::assertSame('read_file', $cues[0]->effectId);
        self::assertSame(11, $cues[0]->sequence);
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('shell_exec', Kind::ShellExec);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Failed, $result->turnOutcome);
        self::assertInstanceOf(EffectDenied::class, $result->error);

        $cues = $emitter->cues;
        self::assertInstanceOf(Denied::class, $cues[0]);
        self::assertSame('shell_exec', $cues[0]->effectId);
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('write_file', Kind::FileWrite);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::WaitingForApproval, $result->turnOutcome);
        self::assertNull($result->error);

        $cues = $emitter->cues;
        self::assertCount(1, $cues);
        self::assertInstanceOf(Paused::class, $cues[0]);
        self::assertSame('write_file', $cues[0]->effectId);
        self::assertSame('Human approval required', $cues[0]->reason);
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('fail_tool', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Failed, $result->turnOutcome);
        self::assertNotNull($result->error);
        self::assertSame('Tool broke', $result->error->getMessage());

        $cues = $emitter->cues;
        self::assertCount(2, $cues);
        self::assertInstanceOf(Authorized::class, $cues[0]);
        self::assertInstanceOf(Failed::class, $cues[1]);
        self::assertSame('fail_tool', $cues[1]->effectId);
        self::assertSame(\RuntimeException::class, $cues[1]->errorClass);
        self::assertSame('Tool broke', $cues[1]->reason);
    }

    #[Test]
    public function builtInNoopRoutesToBuiltInExecutor(): void
    {
        $dispatcher = self::dispatcher(
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();
        $request = self::request('noop', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);
        self::assertNotNull($result->effectOutcome);
        self::assertSame(Resolution::BuiltIn, $result->effectOutcome->resolution);
    }

    #[Test]
    public function builtInHaltReturnsCompleteOutcome(): void
    {
        $dispatcher = self::dispatcher(
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();
        $request = self::request('halt', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

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
        $emitter = new ArrayCueEmitter();
        $request = self::request('search', Kind::CodeSearch, arguments: ['query' => 'test']);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);
        self::assertNotNull($result->effectOutcome);
        self::assertSame(Resolution::LocalTool, $result->effectOutcome->resolution);
    }

    #[Test]
    public function unresolvableEffectReturnsFailed(): void
    {
        $dispatcher = self::dispatcher(
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();
        $request = self::request('nonexistent_tool', Kind::Custom);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unresolvable effect: nonexistent_tool');

        $dispatcher->dispatch($scope, $request, $emitter);
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('cancel_tool', Kind::Custom);

        $this->expectException(Cancelled::class);

        $dispatcher->dispatch($scope, $request, $emitter);
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('read_file', Kind::FileRead, requiresApproval: true);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);

        $cues = $emitter->cues;
        self::assertInstanceOf(Authorized::class, $cues[0]);
    }

    #[Test]
    public function mcpToolRoutesViaMcpRegistry(): void
    {
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(new ScopeStub(), new FakeConnection());

        $dispatcher = self::dispatcher(
            mcpRegistry: $mcpRegistry,
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $scope = new ScopeStub();
        $emitter = new ArrayCueEmitter();
        $request = self::request('mcp_search', Kind::Custom, arguments: ['q' => 'sparta']);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertSame(Turn\Outcome::Continue, $result->turnOutcome);
        self::assertNotNull($result->effectOutcome);
        self::assertSame(Resolution::McpTool, $result->effectOutcome->resolution);
        self::assertSame(['found' => true], $result->data);
    }

    #[Test]
    public function preCancelledScopeThrowsImmediately(): void
    {
        $token = CancellationToken::create();
        $token->cancel();
        $scope = new ScopeStub($token);

        $dispatcher = self::dispatcher(
            grantStore: new FixedGrantStore(self::grant(Kind::Custom)),
        );

        $emitter = new ArrayCueEmitter();
        $request = self::request('noop', Kind::Custom);

        $this->expectException(Cancelled::class);

        $dispatcher->dispatch($scope, $request, $emitter);
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
        $emitter = new ArrayCueEmitter();
        $request = self::request('noop', Kind::Custom);

        $result = $dispatcher->dispatch($scope, $request, $emitter);

        self::assertNotNull($result->effectOutcome);
        self::assertSame(Resolution::BuiltIn, $result->effectOutcome->resolution);
    }

    private static function dispatcher(
        ?Effect\Authorizer $authorizer = null,
        ?Hazard\Scorer $scorer = null,
        ?GrantStore $grantStore = null,
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

final class FixedGrantStore implements GrantStore
{
    /** @var list<Grant> */
    public array $consumed = [];

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
        $this->consumed[] = $grant;
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
    }
}

final class CancellingTool implements Tool
{
    public function __invoke(TaskScope $scope, Context $ctx): Outcome
    {
        throw new Cancelled('Scope cancelled');
    }
}

final class PausingAuthorizer implements Effect\Authorizer
{
    public function evaluate(Effect $effect, ?Grant $grant = null): Decision
    {
        return Decision::paused('Human approval required');
    }
}

final class FakeConnection implements McpConnection
{
    public function tools(TaskScope $scope): array
    {
        return [
            new McpTool(
                name: 'mcp_search',
                description: 'Fake search tool',
                inputSchema: [],
                serverName: 'fake_server',
            ),
        ];
    }

    public function invoke(TaskScope $scope, string $toolName, array $args): Outcome
    {
        return Outcome::routed(Resolution::McpTool, AiProvidersOutcome::succeeded(null, 0), ['found' => true]);
    }

    public function isRunning(): bool
    {
        return true;
    }

    public function disconnect(TaskScope $scope): void
    {
    }
}
