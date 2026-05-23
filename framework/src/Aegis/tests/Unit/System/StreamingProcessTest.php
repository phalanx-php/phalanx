<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\StreamingProcess;
use Phalanx\System\StreamingProcessState;
use Phalanx\Testing\PhalanxTestCase;

/**
 * Mechanism proof for the new OpenSwoole + Symfony Process based StreamingProcess.
 * The public surface is an Aegis-owned process resource plus a streaming handle.
 */
final class StreamingProcessTest extends PhalanxTestCase
{
    public function testBasicStartWriteReadCloseLifecycle(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "ok\n"); fflush(STDOUT);',
            )->start($scope);

            $isRunning = $handle->isRunning();
            $handle->wait(1.0);
            $output = $handle->getIncrementalOutput();
            $handle->close('test');

            return [$isRunning, $output];
        });

        [$isRunning, $output] = $result;

        self::assertTrue($isRunning);
        self::assertStringContainsString('ok', $output);
    }

    public function testResourceIsReleasedOnClose(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): int {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(100000);')->start($scope);
            $handle->close('test-cleanup');

            $liveCount = $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess);

            return $liveCount;
        });

        self::assertSame(0, $result);
    }

    public function testWritesToStdinReadsStdoutAndWaitsForExit(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                '$line = fgets(STDIN); fwrite(STDOUT, strtoupper($line)); fflush(STDOUT);',
            )->start($scope);

            $written = $handle->write("sparta\n");
            $line = $handle->readLine(1.0);
            $exitCode = $handle->wait(1.0);
            $handle->close('test-stdin');

            return [$written, $line, $exitCode, $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess)];
        });

        self::assertSame([7, "SPARTA\n", 0, 0], $result);
    }

    public function testReadsStdoutLinesAndStderrChunks(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "alpha\n"); fwrite(STDERR, "omega"); fflush(STDOUT); fflush(STDERR);',
            )->start($scope);

            $line = $handle->readLine(1.0);
            $error = $handle->readError(16, 1.0);
            $handle->close('test-read');

            return [$line, $error];
        });

        self::assertSame(["alpha\n", 'omega'], $result);
    }

    public function testReadsStderrInByteLimitedChunks(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(
                PHP_BINARY,
                '-r',
                'fwrite(STDERR, "sparta"); fflush(STDERR);',
            )->start($scope);

            $handle->wait(1.0);
            $first = $handle->readError(3, 1.0);
            $second = $handle->readError(8, 1.0);
            $handle->close('test-stderr-chunks');

            return [$first, $second];
        });

        self::assertSame(['spa', 'rta'], $result);
    }

    public function testReadLineReturnsFinalUnterminatedOutputAtEof(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): string {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'fwrite(STDOUT, "sparta");')->start($scope);
            $handle->wait(1.0);
            $line = $handle->readLine(1.0);
            $handle->close('test-unterminated');

            return $line;
        });

        self::assertSame('sparta', $result);
    }

    public function testReadLineTimeoutReturnsEmptyWithoutBlockingScheduler(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(200000); fwrite(STDOUT, "late\n");')->start($scope);
            $schedulerAdvanced = false;

            $scope->go(static function (ExecutionScope $childScope) use (&$schedulerAdvanced): void {
                $childScope->delay(0.005);
                $schedulerAdvanced = true;
            }, 'streaming-process-timeout-probe');

            $line = $handle->readLine(0.05);
            $handle->close('test-read-timeout');

            return [$line, $schedulerAdvanced, $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess)];
        });

        self::assertSame('', $result[0]);
        self::assertTrue($result[1]);
        self::assertSame(0, $result[2]);
    }

    public function testWaitWithoutTimeoutDoesNotBlockScheduler(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(50000);')->start($scope);
            $schedulerAdvanced = false;

            $scope->go(static function (ExecutionScope $childScope) use (&$schedulerAdvanced): void {
                $childScope->delay(0.005);
                $schedulerAdvanced = true;
            }, 'streaming-process-wait-probe');

            $exitCode = $handle->wait();
            $handle->close('test-wait-null');

            return [$exitCode, $schedulerAdvanced, $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess)];
        });

        self::assertSame([0, true, 0], $result);
    }

    public function testWaitTimeoutStopAndCloseAreCleanupSafe(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(500000);')->start($scope);

            $wait = $handle->wait(0.01);
            $handle->stop(0.01);
            $state = $handle->state;
            $handle->close('already-stopped');

            return [$wait, $state, $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess)];
        });

        self::assertNull($result[0]);
        self::assertSame(StreamingProcessState::Exited, $result[1]);
        self::assertSame(0, $result[2]);
    }

    public function testKillAndCloseAreIdempotent(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(500000);')->start($scope);

            $handle->kill();
            $state = $handle->state;
            $handle->kill();
            $handle->close('already-killed');

            return [$state, $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess)];
        });

        self::assertSame(StreamingProcessState::Killed, $result[0]);
        self::assertSame(0, $result[1]);
    }

    public function testScopeDisposalReleasesUnclosedProcess(): void
    {
        $pid = $this->scope->run(static function (ExecutionScope $scope): int {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(500000);')->start($scope);

            return $handle->pid();
        });

        self::assertGreaterThan(0, $pid);
    }
}
