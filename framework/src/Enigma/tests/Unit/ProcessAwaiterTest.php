<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Tests\Unit;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Enigma\Exception\SshTimeoutException;
use Phalanx\Enigma\Support\ProcessAwaiter;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

final class ProcessAwaiterTest extends PhalanxTestCase
{
    public function testProcessOutputExitCodeAndDurationAreCollected(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            return ProcessAwaiter::spawn([
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "athena\n"); fwrite(STDERR, "aegis\n"); exit(7);',
            ], $scope, 1.0);
        });

        self::assertSame(7, $result[0]);
        self::assertSame("athena\n", $result[1]);
        self::assertSame("aegis\n", $result[2]);
        self::assertGreaterThanOrEqual(0.0, $result[3]);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testProcessTimeoutKillsAndReleasesManagedProcess(): void
    {
        $marker = self::tempPath();
        $timedOut = false;

        try {
            $this->scope->run(static function (ExecutionScope $scope) use ($marker): void {
                ProcessAwaiter::spawn([
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDERR, "athena waits\n"); usleep(150000); file_put_contents($argv[1], "alive");',
                    $marker,
                ], $scope, 0.01);
            });
        } catch (SshTimeoutException) {
            $timedOut = true;
        } finally {
            self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
        }

        usleep(250000);

        self::assertTrue($timedOut);
        self::assertFileDoesNotExist($marker);
    }

    public function testScopeCancellationKillsAndReleasesManagedProcess(): void
    {
        $marker = self::tempPath();
        $cancelled = false;

        try {
            $this->scope->run(static function (ExecutionScope $scope) use ($marker): void {
                $token = $scope->cancellation();
                $scope->go(static function (ExecutionScope $childScope) use ($token): void {
                    $childScope->delay(0.01);
                    $token->cancel();
                }, 'enigma-process-cancellation-probe');

                ProcessAwaiter::spawn([
                    PHP_BINARY,
                    '-r',
                    'usleep(150000); file_put_contents($argv[1], "alive");',
                    $marker,
                ], $scope, 1.0);
            });
        } catch (Cancelled) {
            $cancelled = true;
        } finally {
            self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
        }

        usleep(250000);

        self::assertTrue($cancelled);
        self::assertFileDoesNotExist($marker);
    }

    public function testArgvExecutesShellMetacharactersAsLiteralArguments(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            return ProcessAwaiter::spawn([
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, $argv[1]);',
                'athena; echo unsafe',
            ], $scope, 1.0);
        });

        self::assertSame(0, $result[0]);
        self::assertSame('athena; echo unsafe', $result[1]);
    }

    private static function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phalanx-enigma-marker-');
        if ($path === false) {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phalanx-enigma-marker-' . uniqid('', true);
        }

        unlink($path);

        return $path;
    }
}
