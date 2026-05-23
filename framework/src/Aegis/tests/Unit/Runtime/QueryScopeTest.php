<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\QueryScope;
use Phalanx\Runtime\RuntimeContext;
use PHPUnit\Framework\TestCase;

final class QueryScopeTest extends TestCase
{
    public function testRuntimeContextExposesQueryScope(): void
    {
        $context = new RuntimeContext(RuntimeMemory::forLedgerSize(16));

        try {
            self::assertInstanceOf(QueryScope::class, $context->query);
            self::assertSame(0, $context->query->liveCount());
        } finally {
            $context->memory->shutdown();
        }
    }

    public function testQueriesManagedResourcesWithoutOwningLifecycle(): void
    {
        $memory = RuntimeMemory::forLedgerSize(32);

        try {
            $query = new QueryScope($memory);
            $parent = $memory->resources->open(AegisResourceSid::Test, id: 'parent', ownerScopeId: 'scope-1');
            $active = $memory->resources->activate($parent);
            $child = $memory->resources->open(
                AegisResourceSid::TaskRun,
                id: 'child',
                parentResourceId: 'parent',
                ownerScopeId: 'scope-1',
                ownerRunId: 'run-1',
            );
            $edgeChild = $memory->resources->open(AegisResourceSid::TaskRun, id: 'edge-child');

            $memory->resources->addEdge($active->id, $child->id);
            $memory->resources->addEdge($active->id, $edgeChild->id);
            $memory->resources->annotate($active, QueryScopeAnnotationSid::Label, 'parent-resource');
            $memory->resources->close($child, 'done');
            $memory->resources->close($edgeChild, 'done');

            self::assertSame($active->id, $query->get('parent')?->id);
            self::assertSame(1, $query->liveCount());
            self::assertSame(0, $query->liveCount(AegisResourceSid::TaskRun));
            self::assertSame(['parent'], self::ids($query->live()));
            self::assertSame(['child', 'edge-child', 'parent'], self::ids($query->all()));
            self::assertSame(['child', 'edge-child'], self::ids($query->all(AegisResourceSid::TaskRun)));
            self::assertSame(['child', 'parent'], self::ids($query->byOwnerScope('scope-1')));
            self::assertSame(['child'], self::ids($query->byOwnerScope('scope-1', AegisResourceSid::TaskRun)));
            self::assertSame(['child'], self::ids($query->byOwnerRun('run-1')));
            self::assertSame(['child'], self::ids($query->byOwnerRun('run-1', AegisResourceSid::TaskRun)));
            self::assertSame(['child', 'edge-child'], self::ids($query->childrenOf('parent')));
            self::assertSame(
                ['child', 'edge-child'],
                self::ids($query->childrenOf('parent', AegisResourceSid::TaskRun)),
            );
            self::assertSame([], self::ids($query->childrenOf('parent', AegisResourceSid::Test)));
            self::assertSame(['stoa.label' => 'parent-resource'], $query->annotations('parent'));
            self::assertSame(1, $query->stateCounts()[ManagedResourceState::Active->value]);
            self::assertSame(2, $query->stateCounts()[ManagedResourceState::Closed->value]);
            self::assertSame(0, $query->stateCounts(AegisResourceSid::TaskRun)[ManagedResourceState::Active->value]);
            self::assertSame(2, $query->stateCounts(AegisResourceSid::TaskRun)[ManagedResourceState::Closed->value]);
        } finally {
            $memory->shutdown();
        }
    }

    public function testQueryScopeProjectsLeases(): void
    {
        $memory = RuntimeMemory::forLedgerSize(32);

        try {
            $query = new QueryScope($memory);
            $resource = $memory->resources->open(AegisResourceSid::Test, id: 'leased');
            $memory->resources->addLease($resource->id, 'run-1', [
                'lease_type' => 'delivery',
                'domain' => 'ws-frame',
                'resource_key' => '7',
                'mode' => 'flush',
                'acquired_at' => 1234.5,
            ]);

            self::assertSame([
                [
                    'lease_type' => 'delivery',
                    'domain' => 'ws-frame',
                    'resource_key' => '7',
                    'mode' => 'flush',
                    'acquired_at' => 1234.5,
                ],
            ], $query->leases('leased'));
        } finally {
            $memory->shutdown();
        }
    }

    public function testQueryScopeDoesNotProjectExpiredAnnotations(): void
    {
        $memory = RuntimeMemory::forLedgerSize(32);

        try {
            $query = new QueryScope($memory);
            $resource = $memory->resources->open(AegisResourceSid::Test, id: 'annotated');

            $memory->resources->annotate($resource, QueryScopeAnnotationSid::Label, 'current');
            $memory->resources->annotate($resource, QueryScopeAnnotationSid::Temporary, 'expired', ttl: 0.001);

            usleep(2_000);

            self::assertSame('fallback', $memory->resources->annotation(
                'annotated',
                QueryScopeAnnotationSid::Temporary,
                'fallback',
            ));
            self::assertSame(['stoa.label' => 'current'], $query->annotations('annotated'));
        } finally {
            $memory->shutdown();
        }
    }

    /**
     * @param list<\Phalanx\Runtime\Memory\ManagedResource> $resources
     * @return list<string>
     */
    private static function ids(array $resources): array
    {
        $ids = array_map(static fn($resource): string => $resource->id, $resources);
        sort($ids);

        return $ids;
    }
}

enum QueryScopeAnnotationSid: string implements \Phalanx\Runtime\Identity\RuntimeAnnotationId
{
    case Label = 'stoa.label';
    case Temporary = 'stoa.temporary';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
