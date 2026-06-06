<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Acceptance;

use Phalanx\Agents\Effect\Context as EffectContext;
use Phalanx\Agents\Effect\Dispatcher;
use Phalanx\Agents\Effect\Outcome as EffectOutcome;
use Phalanx\Agents\Effect\Resolution;
use Phalanx\Agents\Grant\MemoryGrantStore;
use Phalanx\Agents\Mcp\McpRegistry;
use Phalanx\Agents\Stream\ArrayCueEmitter;
use Phalanx\Agents\Testing\ScopeStub;
use Phalanx\Agents\Tool\Tool;
use Phalanx\Agents\Tool\ToolRegistry;
use Phalanx\AiProviders\Cue\Effect\Executed;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectDispatcherRoutingTest extends TestCase
{
    #[Test]
    public function fileReadRequestRoutesThroughToolExecutorAndEmitsExecutedCue(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');
        $scope = new ScopeStub();
        $registry = new ToolRegistry();
        $registry->register('file.read', RoutingEchoTool::class);

        $grantStore = new MemoryGrantStore();
        $grantStore->remember($scope, Grant::of(
            id: 'grant_routing',
            subject: 'agent-test-agent',
            allowedEffects: [Kind::FileRead],
            grantScope: 'session',
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
            agentId: 'agent-test-agent',
            at: $at,
            effectId: 'file.read',
            kind: Kind::FileRead,
            summary: 'read config',
        );

        $emitter = new ArrayCueEmitter();
        $dispatcher->dispatch($scope, $request, $emitter);

        $emitted = $emitter->cues;
        $types = array_map(static fn($cue): string => $cue->type, $emitted);

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

