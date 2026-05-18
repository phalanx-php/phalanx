<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Grant\MemoryGrantStore;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Panoply\Cue\Effect\Executed;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectDispatcherRoutingTest extends TestCase
{
    #[Test]
    public function fileReadRequestRoutesThroughToolExecutorAndEmitsExecutedCue(): void
    {
        $at       = new \DateTimeImmutable('2026-05-18T10:00:00Z');
        $scope    = new ScopeStub();
        $registry = new ToolRegistry();
        $registry->register('file.read', RoutingEchoTool::class);

        $grantStore = new MemoryGrantStore();
        $grantStore->remember($scope, Grant::of(
            id: 'grant_routing',
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

        $request = new Requested(
            id: 'cue_r1',
            sequence: 1,
            activityId: 'act_routing',
            invocationId: null,
            agentId: 'athena-test-agent',
            at: $at,
            effectId: 'file.read',
            kind: Kind::FileRead,
            summary: 'read config',
        );

        $stream = CompositeStream::wrap($scope, Stream::from([]));
        $dispatcher->dispatch($scope, $request, $stream);

        $emitted = $stream->stream()->toArray();
        $types   = array_map(static fn($cue): string => $cue->type, $emitted);

        self::assertContains('cue.effect.authorized', $types);
        self::assertContains('cue.effect.executed', $types);

        $executed = array_values(array_filter($emitted, static fn($c): bool => $c instanceof Executed));
        self::assertCount(1, $executed);
        self::assertSame('file.read', $executed[0]->effectId);
    }
}

final class RoutingEchoTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: 'content');
    }
}
