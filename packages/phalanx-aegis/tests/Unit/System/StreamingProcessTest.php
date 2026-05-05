<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\StreamingProcess;
use Phalanx\System\StreamingProcessException;
use Phalanx\System\StreamingProcessExit;
use Phalanx\Testing\PhalanxTestCase;

final class StreamingProcessTest extends PhalanxTestCase
{
    public function testWritesStdinAndReadsStdoutLine(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                'while (($line = fgets(STDIN)) !== false) { fwrite(STDOUT, strtoupper($line)); fflush(STDOUT); }',
            )->start($scope);

            $written = $handle->writeLine('hello', 1.0);
            $handle->closeInput();
            $line = $handle->readLine(1.0);
            $exit = $handle->wait(1.0);

            return [$written, $line, $exit];
        });

        [$written, $line, $exit] = $result;

        self::assertSame(6, $written);
        self::assertSame("HELLO\n", $line);
        self::assertInstanceOf(StreamingProcessExit::class, $exit);
        self::assertTrue($exit->successful);
    }

    public function testSeparatesStdoutAndStderr(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "out\n"); fflush(STDOUT); fwrite(STDERR, "err\n"); fflush(STDERR);',
            )->start($scope);

            $stdout = $handle->readLine(1.0);
            $stderr = $handle->readError(64, 1.0);
            $exit = $handle->wait(1.0);

            return [$stdout, $stderr, $exit];
        });

        [$stdout, $stderr, $exit] = $result;

        self::assertSame("out\n", $stdout);
        self::assertSame("err\n", $stderr);
        self::assertInstanceOf(StreamingProcessExit::class, $exit);
        self::assertTrue($exit->successful);
    }

    public function testReadReturnsEmptyOnTimeout(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                'usleep(200000); fwrite(STDOUT, "late\n"); fflush(STDOUT);',
            )->start($scope);

            $early = $handle->read(64, 0.02);
            $late = $handle->readLine(1.0);
            $exit = $handle->wait(1.0);

            return [$early, $late, $exit];
        });

        [$early, $late, $exit] = $result;

        self::assertSame('', $early);
        self::assertSame("late\n", $late);
        self::assertInstanceOf(StreamingProcessExit::class, $exit);
        self::assertTrue($exit->successful);
    }

    public function testReadLineTimeoutPreservesPartialBufferedLine(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "hel"); fflush(STDOUT); usleep(200000); fwrite(STDOUT, "lo\n"); fflush(STDOUT);',
            )->start($scope);

            $early = $handle->readLine(0.02);
            $late = $handle->readLine(1.0);
            $exit = $handle->wait(1.0);

            return [$early, $late, $exit];
        });

        [$early, $late, $exit] = $result;

        self::assertSame('', $early);
        self::assertSame("hello\n", $late);
        self::assertInstanceOf(StreamingProcessExit::class, $exit);
        self::assertTrue($exit->successful);
    }

    public function testNonZeroExitIsCaptured(): void
    {
        $exit = $this->scope->run(static function (ExecutionScope $scope): ?StreamingProcessExit {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'exit(7);')->start($scope);

            return $handle->wait(1.0);
        });

        self::assertInstanceOf(StreamingProcessExit::class, $exit);
        self::assertSame(7, $exit->exitCode);
        self::assertFalse($exit->successful);
    }

    public function testStopEscalatesToKillWhenChildIgnoresSigterm(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('pcntl is required to install a SIGTERM-ignoring child handler.');
        }

        $exit = $this->scope->run(static function (ExecutionScope $scope): StreamingProcessExit {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                self::sigtermIgnoringChild(),
            )->start($scope);

            self::assertSame("ready\n", $handle->readLine(1.0));

            return $handle->stop(gracefulTimeout: 0.05, forceTimeout: 1.0);
        });

        self::assertTrue($exit->stopped);
        self::assertTrue($exit->killed);
        self::assertSame(9, $exit->signal);
    }

    public function testRecordsRuntimeEventsAndAnnotations(): void
    {
        $events = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'exit(0);')->start($scope);
            $resources = $scope->runtime->memory->resources->all(AegisResourceSid::StreamingProcess);

            self::assertCount(1, $resources);

            $resource = $resources[0];
            self::assertSame(
                (string) $handle->pid(),
                $scope->runtime->memory->resources->annotation($resource->id, AegisAnnotationSid::ProcessPid),
            );
            self::assertSame(
                'running',
                $scope->runtime->memory->resources->annotation($resource->id, AegisAnnotationSid::ProcessState),
            );

            $handle->wait(1.0);

            return array_map(
                static fn($event): string => $event->type,
                $scope->runtime->memory->events->recent(),
            );
        });

        self::assertContains(AegisEventSid::ProcessStarted->value(), $events);
        self::assertContains(AegisEventSid::ProcessExited->value(), $events);
    }

    public function testScopeDisposalTerminatesAndReleasesLiveProcess(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            StreamingProcess::from(PHP_BINARY, '-r', 'sleep(30);')->start($scope);

            self::assertSame(
                1,
                $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess),
            );
        });

        $this->scope->expect->handles()->closed(AegisResourceSid::StreamingProcess);
    }

    public function testStartFailurePreservesDiagnosticMessage(): void
    {
        $caught = null;

        try {
            $this->scope->run(static function (ExecutionScope $scope): void {
                StreamingProcess::from('/definitely/not/a/phalanx-binary')->start($scope);
            });
        } catch (StreamingProcessException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('Failed to start streaming process', $caught->getMessage());
    }

    private static function sigtermIgnoringChild(): string
    {
        return <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function (): void {});
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
while (true) {
    usleep(10000);
}
PHP;
    }
}
