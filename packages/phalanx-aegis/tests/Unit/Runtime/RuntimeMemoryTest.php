<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use Phalanx\Application;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeAnnotationRejected;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\Memory\StaleManagedResourceHandle;
use Phalanx\Task\Task;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeMemoryTest extends TestCase
{
    public function testIdsCountersAndClaimsUseManagedRuntimeMemory(): void
    {
        $memory = RuntimeMemory::forLedgerSize(32);

        $first = $memory->ids->next('request');
        $second = $memory->ids->next('request');

        self::assertSame(1, $first);
        self::assertSame(2, $second);
        self::assertSame(1, $memory->counters->incr('jobs.running'));
        self::assertSame(2, $memory->counters->incr('jobs.running'));
        self::assertSame(1, $memory->counters->decr('jobs.running'));
        self::assertTrue($memory->claims->claim('dedupe:1', ttl: 0.01));
        self::assertFalse($memory->claims->claim('dedupe:1', ttl: 0.01));

        usleep(20_000);

        self::assertSame(1, $memory->sweepExpired());
        self::assertTrue($memory->claims->claim('dedupe:1', ttl: 1.0));

        $memory->shutdown();
    }

    public function testLifecycleListenersCannotFailTheRecorder(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);
        $memory->events->listen(static function (): void {
            throw new RuntimeException('listener failed');
        });

        $event = $memory->events->record('runtime.test', scopeId: 'scope-1', runId: 'run-1');

        self::assertSame('runtime.test', $event->type);
        self::assertCount(1, $memory->events->listenerErrors());
        self::assertCount(1, $memory->events->recent());

        $memory->shutdown();
    }

    public function testManagedResourcesEnforceGenerationAndTerminalTruth(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);

        $opened = $memory->resources->open('stoa.http_request', id: 'request-1');
        $active = $memory->resources->activate($opened);
        $memory->resources->annotate($active, 'stoa.route', 'users.show');
        $closed = $memory->resources->close($active, 'status:200');

        self::assertSame(ManagedResourceState::Closed, $memory->resources->get('request-1')?->state);
        self::assertSame('users.show', $memory->resources->annotation('request-1', 'stoa.route'));

        $late = $memory->resources->abort('request-1', 'client_disconnected');
        self::assertSame($closed->generation, $late->generation);
        self::assertSame(ManagedResourceState::Closed, $memory->resources->get('request-1')?->state);

        $memory->shutdown();
    }

    public function testManagedResourcesRejectStaleHandlesAndUnboundedAnnotations(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);
        $opened = $memory->resources->open('archon.command', id: 'command-1');
        $memory->resources->activate($opened);

        try {
            $memory->resources->close($opened);
            self::fail('Expected stale handle rejection.');
        } catch (StaleManagedResourceHandle) {
            self::assertSame(ManagedResourceState::Active, $memory->resources->get('command-1')?->state);
        } finally {
            $memory->shutdown();
        }
    }

    public function testManagedResourcesRejectNonNamespacedAnnotations(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);
        $resource = $memory->resources->open('aegis.test', id: 'test-1');

        try {
            $memory->resources->annotate($resource, 'route', 'users.show');
            self::fail('Expected annotation rejection.');
        } catch (RuntimeAnnotationRejected) {
            self::assertSame([], $memory->resources->annotations('test-1'));
        } finally {
            $memory->shutdown();
        }
    }

    public function testScopeRuntimePropertyResolvesMemoryBranchFromContainer(): void
    {
        $app = Application::starting([
            RuntimeMemoryConfig::CONTEXT_KEY => [
                'resource_rows' => 64,
                'edge_rows' => 32,
                'lease_rows' => 32,
                'annotation_rows' => 64,
                'event_rows' => 32,
                'counter_rows' => 32,
                'claim_rows' => 32,
                'symbol_rows' => 32,
            ],
        ])->compile();

        $result = $app->run(Task::named(
            'runtime.memory.property',
            static function ($scope): array {
                return [
                    'id' => $scope->runtime->memory->ids->next('demo'),
                    'counter' => $scope->runtime->memory->counters->incr('demo.counter'),
                    'claim' => $scope->runtime->memory->claims->claim('demo.claim', ttl: 1.0),
                ];
            },
        ));

        self::assertSame(['id' => 1, 'counter' => 1, 'claim' => true], $result);
    }
}
