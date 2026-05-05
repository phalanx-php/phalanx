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
        self::assertSame('StreamingProcess', AegisResourceSid::StreamingProcess->key());
        self::assertSame('aegis.streaming_process', AegisResourceSid::StreamingProcess->value());
        self::assertSame('RunState', AegisAnnotationSid::RunState->key());
        self::assertSame('aegis.run_state', AegisAnnotationSid::RunState->value());
        self::assertSame('ProcessCommand', AegisAnnotationSid::ProcessCommand->key());
        self::assertSame('aegis.process_command', AegisAnnotationSid::ProcessCommand->value());
        self::assertSame('ProcessCwd', AegisAnnotationSid::ProcessCwd->key());
        self::assertSame('aegis.process_cwd', AegisAnnotationSid::ProcessCwd->value());
        self::assertSame('ProcessExitCode', AegisAnnotationSid::ProcessExitCode->key());
        self::assertSame('aegis.process_exit_code', AegisAnnotationSid::ProcessExitCode->value());
        self::assertSame('ProcessPid', AegisAnnotationSid::ProcessPid->key());
        self::assertSame('aegis.process_pid', AegisAnnotationSid::ProcessPid->value());
        self::assertSame('ProcessSignal', AegisAnnotationSid::ProcessSignal->key());
        self::assertSame('aegis.process_signal', AegisAnnotationSid::ProcessSignal->value());
        self::assertSame('ProcessState', AegisAnnotationSid::ProcessState->key());
        self::assertSame('aegis.process_state', AegisAnnotationSid::ProcessState->value());
        self::assertSame('RunRunning', AegisEventSid::RunRunning->key());
        self::assertSame('run.running', AegisEventSid::RunRunning->value());
        self::assertSame('ProcessExited', AegisEventSid::ProcessExited->key());
        self::assertSame('process.exited', AegisEventSid::ProcessExited->value());
        self::assertSame('ProcessKilled', AegisEventSid::ProcessKilled->key());
        self::assertSame('process.killed', AegisEventSid::ProcessKilled->value());
        self::assertSame('ProcessReadFailed', AegisEventSid::ProcessReadFailed->key());
        self::assertSame('process.read_failed', AegisEventSid::ProcessReadFailed->value());
        self::assertSame('ProcessStarted', AegisEventSid::ProcessStarted->key());
        self::assertSame('process.started', AegisEventSid::ProcessStarted->value());
        self::assertSame('ProcessStopped', AegisEventSid::ProcessStopped->key());
        self::assertSame('process.stopped', AegisEventSid::ProcessStopped->value());
        self::assertSame('ProcessWriteFailed', AegisEventSid::ProcessWriteFailed->key());
        self::assertSame('process.write_failed', AegisEventSid::ProcessWriteFailed->value());
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
