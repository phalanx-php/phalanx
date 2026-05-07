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
        $this->expectException(SshTimeoutException::class);

        try {
            $this->scope->run(static function (ExecutionScope $scope): void {
                ProcessAwaiter::spawn([
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDERR, "athena waits\n"); usleep(500000);',
                ], $scope, 0.01);
            });
        } finally {
            self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
        }
    }

    public function testScopeCancellationKillsAndReleasesManagedProcess(): void
    {
        $this->expectException(Cancelled::class);

        try {
            $this->scope->run(static function (ExecutionScope $scope): void {
                $token = $scope->cancellation();
                $scope->go(static function (ExecutionScope $childScope) use ($token): void {
                    $childScope->delay(0.01);
                    $token->cancel();
                }, 'enigma-process-cancellation-probe');

                ProcessAwaiter::spawn([
                    PHP_BINARY,
                    '-r',
                    'usleep(500000);',
                ], $scope, 1.0);
            });
        } finally {
            self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
        }
    }

    public function testArgvKeepsArgumentsSeparatedWithoutShellEscaping(): void
    {
        $argv = ProcessAwaiter::argv('ssh', ['-p', '22', '--', 'printf "%s" athena']);

        self::assertSame(['ssh', '-p', '22', '--', 'printf "%s" athena'], $argv);
    }
}
