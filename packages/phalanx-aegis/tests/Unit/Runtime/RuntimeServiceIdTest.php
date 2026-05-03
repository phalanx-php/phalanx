<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisCounterSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeCounterId;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\TestCase;

class RuntimeServiceIdTest extends TestCase
{
    public function testAegisRuntimeIdsExposeEnumKeyAndCanonicalValue(): void
    {
        self::assertSame('TaskRun', AegisResourceSid::TaskRun->key());
        self::assertSame('aegis.task_run', AegisResourceSid::TaskRun->value());
        self::assertSame('RunState', AegisAnnotationSid::RunState->key());
        self::assertSame('aegis.run_state', AegisAnnotationSid::RunState->value());
        self::assertSame('RunRunning', AegisEventSid::RunRunning->key());
        self::assertSame('run.running', AegisEventSid::RunRunning->value());
        self::assertSame('RuntimeEventsDropped', AegisCounterSid::RuntimeEventsDropped->key());
        self::assertSame('aegis.runtime.events.dropped', AegisCounterSid::RuntimeEventsDropped->value());
    }

    public function testRuntimeIdEnumsCarryCategoryIntent(): void
    {
        self::assertInstanceOf(RuntimeResourceId::class, AegisResourceSid::TaskRun);
        self::assertInstanceOf(RuntimeAnnotationId::class, AegisAnnotationSid::RunState);
        self::assertInstanceOf(RuntimeEventId::class, AegisEventSid::RunRunning);
        self::assertInstanceOf(RuntimeCounterId::class, AegisCounterSid::RuntimeEventsDropped);
    }

    public function testRuntimeMemoryAcceptsTypedIdsAndStoresCanonicalValues(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);

        try {
            $handle = $memory->resources->open(AegisResourceSid::Test, id: 'resource-1');
            $memory->resources->annotate($handle, AegisAnnotationSid::RunState, 'running');
            $memory->resources->recordEvent($handle, AegisEventSid::RunRunning);
            $memory->counters->incr(AegisCounterSid::RuntimeEventsDropped);

            self::assertSame('aegis.test', $memory->resources->get('resource-1')?->type);
            self::assertSame('running', $memory->resources->annotation('resource-1', AegisAnnotationSid::RunState));
            self::assertSame('run.running', $memory->events->recent()[1]->type);
            self::assertSame(1, $memory->counters->get(AegisCounterSid::RuntimeEventsDropped));
        } finally {
            $memory->shutdown();
        }
    }
}
