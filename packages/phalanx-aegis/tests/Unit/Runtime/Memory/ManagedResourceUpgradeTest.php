<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime\Memory;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceException;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\StaleManagedResourceHandle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Coverage for `ManagedResourceRegistry::upgrade()` — the typed retag
 * operation Stoa uses when an HTTP connection escalates to SSE/WS mid-
 * stream. The registry preserves the resource id and lifecycle while
 * bumping generation and switching the type symbol.
 */
final class ManagedResourceUpgradeTest extends TestCase
{
    private RuntimeMemory $memory;

    protected function setUp(): void
    {
        $this->memory = RuntimeMemory::forLedgerSize(64);
    }

    protected function tearDown(): void
    {
        $this->memory->shutdown();
    }

    public function testUpgradeChangesTypeAndBumpsGeneration(): void
    {
        $handle = $this->memory->resources->open(AegisResourceSid::Test, id: 'sse-1');
        $active = $this->memory->resources->activate($handle);
        $originalGeneration = $active->generation;

        $upgraded = $this->memory->resources->upgrade($active, 'stoa.sse_stream');

        self::assertSame('sse-1', $upgraded->id);
        self::assertSame('stoa.sse_stream', $upgraded->type);
        self::assertSame($originalGeneration + 1, $upgraded->generation);
    }

    public function testUpgradePreservesResourceLifecycle(): void
    {
        $handle = $this->memory->resources->open(AegisResourceSid::Test, id: 'ws-1');
        $active = $this->memory->resources->activate($handle);

        $upgraded = $this->memory->resources->upgrade($active, 'stoa.ws_session');

        $resource = $this->memory->resources->get('ws-1');
        self::assertNotNull($resource);
        self::assertSame(ManagedResourceState::Active, $resource->state);
        self::assertSame('stoa.ws_session', $resource->type);
        self::assertSame($upgraded->generation, $resource->generation);
    }

    public function testUpgradeIsIdempotentForSameType(): void
    {
        $handle = $this->memory->resources->open(AegisResourceSid::Test, id: 'noop-1');
        $active = $this->memory->resources->activate($handle);

        $reupgraded = $this->memory->resources->upgrade($active, AegisResourceSid::Test);

        self::assertSame($active->generation, $reupgraded->generation);
        self::assertSame($active->type, $reupgraded->type);
    }

    public function testUpgradeRejectsStaleHandle(): void
    {
        $handle = $this->memory->resources->open(AegisResourceSid::Test, id: 'stale-1');
        $this->memory->resources->activate($handle);

        $this->expectException(StaleManagedResourceHandle::class);
        $this->memory->resources->upgrade($handle, 'stoa.sse_stream');
    }

    public function testUpgradeRejectsTerminalResource(): void
    {
        $handle = $this->memory->resources->open(AegisResourceSid::Test, id: 'term-1');
        $active = $this->memory->resources->activate($handle);
        $closed = $this->memory->resources->close($active);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('terminal');
        $this->memory->resources->upgrade($closed, 'stoa.sse_stream');
    }

    public function testUpgradeRejectsMissingResource(): void
    {
        $this->expectException(ManagedResourceException::class);
        $this->memory->resources->upgrade('does-not-exist', 'stoa.sse_stream');
    }

    public function testUpgradeRecordsLifecycleEvent(): void
    {
        $handle = $this->memory->resources->open(AegisResourceSid::Test, id: 'evt-1');
        $active = $this->memory->resources->activate($handle);

        $eventsBefore = $this->memory->tables->resourceEvents->count();
        $this->memory->resources->upgrade($active, 'stoa.sse_stream');

        self::assertGreaterThan(
            $eventsBefore,
            $this->memory->tables->resourceEvents->count(),
            'upgrade() should record a resource lifecycle event',
        );
    }
}
