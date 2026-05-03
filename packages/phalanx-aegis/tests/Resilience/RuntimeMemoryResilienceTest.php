<?php

declare(strict_types=1);

namespace Phalanx\Tests\Resilience;

use Phalanx\Runtime\Identity\AegisCounterSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryCapacityExceeded;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;

class RuntimeMemoryResilienceTest extends PhalanxTestCase
{
    public function testAtomicIdsRemainUniqueUnderCoroutineContention(): void
    {
        $ids = $this->scope->run(static function (ExecutionScope $scope): array {
            $tasks = [];
            for ($i = 0; $i < 128; $i++) {
                $tasks[] = Task::of(
                    static fn(ExecutionScope $taskScope): int => $taskScope->runtime->memory->ids->next('contention'),
                );
            }

            return $scope->concurrent($tasks);
        });

        sort($ids);

        self::assertSame(range(1, 128), $ids);
        $this->scope->expect->runtime()->clean();
    }

    public function testReleaseRemovesResourceEdgesLeasesAndAnnotations(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);

        try {
            $parent = $memory->resources->open(AegisResourceSid::Test, id: 'parent');
            $child = $memory->resources->open(AegisResourceSid::Test, id: 'child', parentResourceId: 'parent');
            $memory->resources->addEdge($parent->id, $child->id);
            $memory->resources->annotate($parent, RuntimeMemoryResilienceAnnotationSid::Label, 'parent');
            $memory->resources->addLease($parent->id, 'run-1', [
                'lease_type' => 'test.lease',
                'domain' => 'runtime',
                'resource_key' => 'parent',
                'mode' => 'exclusive',
                'acquired_at' => microtime(true),
            ]);

            self::assertSame(1, $memory->tables->resourceEdges->count());
            self::assertSame(1, $memory->tables->resourceLeases->count());
            self::assertSame(1, $memory->tables->resourceAnnotations->count());

            $memory->resources->release($parent->id);

            self::assertNull($memory->resources->get($parent->id));
            self::assertSame(0, $memory->tables->resourceEdges->count());
            self::assertSame(0, $memory->tables->resourceLeases->count());
            self::assertSame(0, $memory->tables->resourceAnnotations->count());
        } finally {
            $memory->shutdown();
        }
    }

    public function testResourceCapacityFailureIsExplicit(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig(
            resourceRows: 1,
            edgeRows: 8,
            leaseRows: 8,
            annotationRows: 8,
            eventRows: 8,
            counterRows: 32,
            claimRows: 8,
            symbolRows: 32,
        ));

        try {
            for ($i = 0; $i < 1024; $i++) {
                $memory->resources->open(AegisResourceSid::Test, id: "resource-{$i}");
            }

            self::fail('Expected resource table capacity to be exhausted.');
        } catch (RuntimeMemoryCapacityExceeded) {
            self::assertGreaterThan(0, $memory->tables->resources->count());
        } finally {
            $memory->shutdown();
        }
    }

    public function testAnnotationCapacityFailureIsExplicit(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig(
            resourceRows: 8,
            edgeRows: 8,
            leaseRows: 8,
            annotationRows: 1,
            eventRows: 8,
            counterRows: 32,
            claimRows: 8,
            symbolRows: 32,
        ));

        try {
            $handle = $memory->resources->open(AegisResourceSid::Test, id: 'one');
            for ($i = 0; $i < 1024; $i++) {
                $memory->resources->annotate($handle, "test.annotation_{$i}", (string) $i);
            }

            self::fail('Expected annotation table capacity to be exhausted.');
        } catch (RuntimeMemoryCapacityExceeded) {
            self::assertGreaterThan(0, $memory->tables->resourceAnnotations->count());
        } finally {
            $memory->shutdown();
        }
    }

    public function testEventOverflowIsDiagnosticAndDoesNotChangeCurrentTruth(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig(
            resourceRows: 8,
            edgeRows: 8,
            leaseRows: 8,
            annotationRows: 8,
            eventRows: 2,
            counterRows: 32,
            claimRows: 8,
            symbolRows: 32,
        ));

        try {
            $handle = $memory->resources->open(AegisResourceSid::Test, id: 'one');
            $active = $memory->resources->activate($handle);

            for ($i = 0; $i < 8; $i++) {
                $memory->resources->recordEvent($active, AegisEventSid::RunRunning);
            }

            self::assertSame(ManagedResourceState::Active, $memory->resources->get('one')?->state);
            self::assertGreaterThan(0, $memory->counters->get(AegisCounterSid::RuntimeEventsDropped));
            self::assertCount(2, $memory->events->recent());
        } finally {
            $memory->shutdown();
        }
    }
}

enum RuntimeMemoryResilienceAnnotationSid: string implements RuntimeAnnotationId
{
    case Label = 'test.label';
    case Phase = 'test.phase';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
