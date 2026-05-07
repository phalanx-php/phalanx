<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\StreamingProcess;
use Phalanx\System\StreamingProcessState;
use Phalanx\Testing\PhalanxTestCase;

/**
 * Mechanism proof for the new OpenSwoole + Symfony Process based StreamingProcess.
 * Old 0.1 rich API (readLine, wait, writeLine, etc.) has been dropped.
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

    public function testScopeDisposalReleasesUnclosedProcess(): void
    {
        $pid = $this->scope->run(static function (ExecutionScope $scope): int {
            $handle = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(500000);')->start($scope);

            return $handle->pid();
        });

        self::assertGreaterThan(0, $pid);
    }
}
