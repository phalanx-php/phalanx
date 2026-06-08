<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Tests\Unit;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Ssh\Exception\SshTimeoutException;
use Phalanx\Ssh\Support\ProcessAwaiter;
use Phalanx\Mark\Mark;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProcessAwaiterTest extends PhalanxTestCase
{
    #[Test]
    public function processOutputExitCodeAndDurationAreCollected(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            return ProcessAwaiter::spawn(
                $scope,
                [
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT, "agent\n"); fwrite(STDERR, "runtime\n"); exit(7);',
                ],
                1.0,
            );
        });

        self::assertSame(7, $result[0]);
        self::assertSame("agent\n", $result[1]);
        self::assertSame("runtime\n", $result[2]);
        self::assertGreaterThanOrEqual(0.0, $result[3]);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    #[Test]
    public function processTimeoutKillsAndReleasesManagedProcess(): void
    {
        $marker = $this->tempPath();
        $timedOut = false;

        try {
            $this->scope->run(static function (ExecutionScope $scope) use ($marker): void {
                ProcessAwaiter::spawn(
                    $scope,
                    [
                        PHP_BINARY,
                        '-r',
                        'fwrite(STDERR, "agent waits\n"); usleep(150000); file_put_contents($argv[1], "alive");',
                        $marker,
                    ],
                    0.01,
                );
            });
        } catch (SshTimeoutException) {
            $timedOut = true;
        } finally {
            self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
        }

        self::assertTrue($timedOut);
        $this->assertFileRemainsMissing($marker, Mark::ms(250));
    }

    #[Test]
    public function scopeCancellationKillsAndReleasesManagedProcess(): void
    {
        $marker = $this->tempPath();
        $cancelled = false;

        try {
            $this->scope->run(static function (ExecutionScope $scope) use ($marker): void {
                $token = $scope->cancellation();
                $scope->go(static function (ExecutionScope $childScope) use ($token): void {
                    $childScope->delay(Mark::ms(10));
                    $token->cancel();
                }, 'ssh-process-cancellation-probe');

                ProcessAwaiter::spawn(
                    $scope,
                    [
                        PHP_BINARY,
                        '-r',
                        'usleep(150000); file_put_contents($argv[1], "alive");',
                        $marker,
                    ],
                    1.0,
                );
            });
        } catch (Cancelled) {
            $cancelled = true;
        } finally {
            self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
        }

        self::assertTrue($cancelled);
        $this->assertFileRemainsMissing($marker, Mark::ms(250));
    }

    #[Test]
    public function argvExecutesShellMetacharactersAsLiteralArguments(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            return ProcessAwaiter::spawn(
                $scope,
                [
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT, $argv[1]);',
                    'agent; echo unsafe',
                ],
                1.0,
            );
        });

        self::assertSame(0, $result[0]);
        self::assertSame('agent; echo unsafe', $result[1]);
    }

    private function tempPath(): string
    {
        return $this->tempWorkspace('phalanx-ssh-marker-')->missingPath(uniqid('marker-', true));
    }

    private function assertFileRemainsMissing(string $path, Mark $duration): void
    {
        $this->scope->run(static function (ExecutionScope $scope) use ($duration): void {
            $scope->delay($duration);
        });

        self::assertFileDoesNotExist($path);
    }
}
