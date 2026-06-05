<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Runtime;

use Phalanx\Runtime\Identity\RuntimeAnnotationSid;
use Phalanx\Runtime\Identity\RuntimeCounterSid;
use Phalanx\Runtime\Identity\RuntimeEventSid;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeCounterId;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\TestCase;

class RuntimeServiceIdTest extends TestCase
{
    public function testScopedRuntimeIdsExposeEnumKeyAndCanonicalValue(): void
    {
        self::assertSame('TaskRun', RuntimeResourceSid::TaskRun->key());
        self::assertSame('runtime.task_run', RuntimeResourceSid::TaskRun->value());
        self::assertSame('StreamingProcess', RuntimeResourceSid::StreamingProcess->key());
        self::assertSame('runtime.streaming_process', RuntimeResourceSid::StreamingProcess->value());
        self::assertSame('RunState', RuntimeAnnotationSid::RunState->key());
        self::assertSame('runtime.run_state', RuntimeAnnotationSid::RunState->value());
        self::assertSame('ProcessCommand', RuntimeAnnotationSid::ProcessCommand->key());
        self::assertSame('runtime.process_command', RuntimeAnnotationSid::ProcessCommand->value());
        self::assertSame('ProcessCwd', RuntimeAnnotationSid::ProcessCwd->key());
        self::assertSame('runtime.process_cwd', RuntimeAnnotationSid::ProcessCwd->value());
        self::assertSame('ProcessExitCode', RuntimeAnnotationSid::ProcessExitCode->key());
        self::assertSame('runtime.process_exit_code', RuntimeAnnotationSid::ProcessExitCode->value());
        self::assertSame('ProcessPid', RuntimeAnnotationSid::ProcessPid->key());
        self::assertSame('runtime.process_pid', RuntimeAnnotationSid::ProcessPid->value());
        self::assertSame('ProcessSignal', RuntimeAnnotationSid::ProcessSignal->key());
        self::assertSame('runtime.process_signal', RuntimeAnnotationSid::ProcessSignal->value());
        self::assertSame('ProcessState', RuntimeAnnotationSid::ProcessState->key());
        self::assertSame('runtime.process_state', RuntimeAnnotationSid::ProcessState->value());
        self::assertSame('RunRunning', RuntimeEventSid::RunRunning->key());
        self::assertSame('run.running', RuntimeEventSid::RunRunning->value());
        self::assertSame('ProcessExited', RuntimeEventSid::ProcessExited->key());
        self::assertSame('process.exited', RuntimeEventSid::ProcessExited->value());
        self::assertSame('ProcessKilled', RuntimeEventSid::ProcessKilled->key());
        self::assertSame('process.killed', RuntimeEventSid::ProcessKilled->value());
        self::assertSame('ProcessReadFailed', RuntimeEventSid::ProcessReadFailed->key());
        self::assertSame('process.read_failed', RuntimeEventSid::ProcessReadFailed->value());
        self::assertSame('ProcessStarted', RuntimeEventSid::ProcessStarted->key());
        self::assertSame('process.started', RuntimeEventSid::ProcessStarted->value());
        self::assertSame('ProcessStopped', RuntimeEventSid::ProcessStopped->key());
        self::assertSame('process.stopped', RuntimeEventSid::ProcessStopped->value());
        self::assertSame('ProcessWriteFailed', RuntimeEventSid::ProcessWriteFailed->key());
        self::assertSame('process.write_failed', RuntimeEventSid::ProcessWriteFailed->value());
        self::assertSame('RuntimeEventsDropped', RuntimeCounterSid::RuntimeEventsDropped->key());
        self::assertSame('runtime.runtime.events.dropped', RuntimeCounterSid::RuntimeEventsDropped->value());
    }

    public function testRuntimeIdEnumsCarryCategoryIntent(): void
    {
        self::assertInstanceOf(RuntimeResourceId::class, RuntimeResourceSid::TaskRun);
        self::assertInstanceOf(RuntimeAnnotationId::class, RuntimeAnnotationSid::RunState);
        self::assertInstanceOf(RuntimeEventId::class, RuntimeEventSid::RunRunning);
        self::assertInstanceOf(RuntimeCounterId::class, RuntimeCounterSid::RuntimeEventsDropped);
    }

    public function testRuntimeMemoryAcceptsTypedIdsAndStoresCanonicalValues(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);

        try {
            $handle = $memory->resources->open(RuntimeResourceSid::Test, id: 'resource-1');
            $memory->resources->annotate($handle, RuntimeAnnotationSid::RunState, 'running');
            $memory->resources->recordEvent($handle, RuntimeEventSid::RunRunning);
            $memory->counters->incr(RuntimeCounterSid::RuntimeEventsDropped);

            self::assertSame('runtime.test', $memory->resources->get('resource-1')?->type);
            self::assertSame('running', $memory->resources->annotation('resource-1', RuntimeAnnotationSid::RunState));
            self::assertSame('run.running', $memory->events->recent()[1]->type);
            self::assertSame(1, $memory->counters->get(RuntimeCounterSid::RuntimeEventsDropped));
        } finally {
            $memory->shutdown();
        }
    }
}
