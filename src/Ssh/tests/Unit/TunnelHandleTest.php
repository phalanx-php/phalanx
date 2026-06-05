<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Tests\Unit;

use Phalanx\Ssh\Exception\SshConnectionException;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\TunnelDirection;
use Phalanx\Ssh\TunnelHandle;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;

final class TunnelHandleTest extends PhalanxTestCase
{
    public function testCloseIsIdempotentAndReleasesManagedProcess(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): array {
            $process = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(500000);')->start($scope);
            $handle = new TunnelHandle(
                localPort: 49222,
                remoteHost: 'agent.internal',
                remotePort: 22,
                direction: TunnelDirection::Local,
                targetCredential: new SshCredential(host: 'agent.internal', user: 'deploy'),
                process: $process,
                scope: $scope,
            );

            $aliveBeforeClose = $handle->isAlive;
            $handle->close();
            $handle->close();

            return [
                $aliveBeforeClose,
                $handle->isAlive,
                $scope->runtime->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess),
            ];
        });

        self::assertSame([true, false, 0], $result);
    }

    public function testExecuteRefusesClosedTunnel(): void
    {
        $this->expectException(SshConnectionException::class);
        $this->expectExceptionMessage('Tunnel is not alive');

        $this->scope->run(static function (ExecutionScope $scope): void {
            $process = StreamingProcess::from(PHP_BINARY, '-r', 'usleep(500000);')->start($scope);
            $handle = new TunnelHandle(
                localPort: 49222,
                remoteHost: 'agent.internal',
                remotePort: 22,
                direction: TunnelDirection::Local,
                targetCredential: null,
                process: $process,
                scope: $scope,
            );

            $handle->close();
            $handle->execute(Task::of(static fn(): bool => true));
        });
    }
}
