<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Tests\Unit;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Ssh\Exception\SshConnectionException;
use Phalanx\Ssh\SshConfig;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\Task\OpenTunnel;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;

final class OpenTunnelTest extends PhalanxTestCase
{
    private ?string $sshBinaryPath = null;

    public function testLocalTunnelWaitsForForwardedPortBeforeReturning(): void
    {
        $this->sshBinaryPath = $this->writeExecutable(<<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$localPort = null;
foreach ($argv as $index => $arg) {
    if ($arg === '-L') {
        $spec = (string) ($argv[$index + 1] ?? '');
        $localPort = (int) explode(':', $spec, 2)[0];
    }
}

if ($localPort === null || $localPort <= 0) {
    fwrite(STDERR, "missing local port\n");
    exit(2);
}

$server = stream_socket_server("tcp://127.0.0.1:{$localPort}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(3);
}

$client = @stream_socket_accept($server, 2);
if (is_resource($client)) {
    fclose($client);
}

usleep(500000);
PHP);
        $localPort = self::freeLocalPort();

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($localPort): array {
            $handle = $scope->execute(new OpenTunnel(
                credential: new SshCredential(host: 'agent.invalid', user: 'deploy'),
                localPort: $localPort,
                remoteHost: 'agent.internal',
                remotePort: 22,
            ));

            $alive = $handle->isAlive;
            $handle->close();

            return [
                $alive,
                $scope->runtime->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess),
            ];
        });

        self::assertSame([true, 0], $result);
    }

    public function testFailedTunnelStartupReleasesManagedProcess(): void
    {
        $this->sshBinaryPath = PHP_BINARY;
        $this->expectException(SshConnectionException::class);

        try {
            $this->scope->run(static function (ExecutionScope $scope): void {
                $scope->execute(new OpenTunnel(
                    credential: new SshCredential(host: 'agent.invalid', user: 'deploy'),
                    localPort: 49222,
                    remoteHost: 'agent.internal',
                    remotePort: 22,
                ));
            });
        } finally {
            self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
        }
    }

    protected function phalanxServices(): Closure
    {
        return static function (Services $services, AppContext $context): void {
            $config = new SshConfig(
                sshBinaryPath: $context->string('sshBinaryPath'),
                defaultTimeoutSeconds: $context->float('defaultTimeoutSeconds', 30.0),
                connectionTimeoutSeconds: 1.0,
                strictHostKeyChecking: false,
            );
            $services->singleton(SshConfig::class)
                ->factory(static fn(): SshConfig => $config);
        };
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function phalanxContext(): array
    {
        return [
            'sshBinaryPath' => $this->sshBinaryPath ?? PHP_BINARY,
        ];
    }

    private function writeExecutable(string $contents): string
    {
        $path = $this->tempWorkspace('phalanx-ssh-fake-')->file(bin2hex(random_bytes(4)), $contents);
        chmod($path, 0755);

        return $path;
    }

    private static function freeLocalPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);

        $name = stream_socket_get_name($server, false);
        fclose($server);

        self::assertIsString($name);
        $colon = strrchr($name, ':');
        self::assertIsString($colon);
        $port = (int) substr($colon, 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }
}
