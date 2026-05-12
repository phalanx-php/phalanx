<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Console\Input;

use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Console\Input\NonInteractiveTtyException;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

/**
 * The "read" tests need a kernel-tracked fd so OpenSwoole's reactor
 * can register the readiness watch. stream_socket_pair returns
 * userspace fds that the reactor cannot track — that path was the
 * source of "ReactorKqueue::add(): Bad file descriptor" warnings in
 * earlier revisions and was hiding the real coroutine yield path.
 * The deeper bug it masked: ConsoleInput was int-casting the PHP
 * stream resource (`(int) $resource`) and passing that to waitEvent,
 * which is the PHP resource ID, not the kernel fd. The fix is to pass
 * the resource itself; OpenSwoole's waitEvent extracts the fd via
 * php_stream_cast(PHP_STREAM_AS_FD).
 *
 * proc_open() yields a PHP stream resource backed by a real kernel
 * pipe (pipe(2)) — same code path that production STDIN uses.
 *
 * Cross-platform: these tests run identically on macOS (kqueue) and
 * Linux (epoll) because OpenSwoole abstracts the reactor backend.
 * The php_stream_cast call is portable PHP API, the pipe(2) syscall
 * is POSIX, and the resulting fd is reactor-trackable on both. No
 * #[RequiresOperatingSystemFamily] gating is needed.
 *
 * The Coroutine\Socket branch of ConsoleInput is exercised by Hermes
 * integration tests where the OpenSwoole server hands the application
 * a real Coroutine\Socket. We don't synthesize one in unit tests
 * because OpenSwoole\Process spawning interacts badly with the test
 * runner's coroutine lifecycle.
 */
final class ConsoleInputTest extends PhalanxTestCase
{
    public function testReadDrainsBytesFromKernelPipeStream(): void
    {
        [$proc, $pipes] = self::spawnPipedChild('echo "hello"; fflush(STDOUT);');

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($pipes): string {
            $input = new ConsoleInput($pipes[1]);
            return $input->read($scope, 64, 1.0);
        });

        self::assertSame('hello', $result);
        self::closePipedChild($proc, $pipes);
    }

    public function testReadReturnsEmptyOnTimeout(): void
    {
        // Child sleeps long enough that our 50ms read timeout fires first.
        [$proc, $pipes] = self::spawnPipedChild('usleep(500000);');

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($pipes): string {
            $input = new ConsoleInput($pipes[1]);
            return $input->read($scope, 64, 0.05);
        });

        self::assertSame('', $result);
        self::closePipedChild($proc, $pipes);
    }

    public function testReadLineDrainsLinedBytesFromKernelPipeStream(): void
    {
        [$proc, $pipes] = self::spawnPipedChild('echo "alpha\n"; fflush(STDOUT);');

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($pipes): string {
            $input = new ConsoleInput($pipes[1]);
            return $input->readLine($scope, 1.0);
        });

        self::assertSame("alpha\n", $result);
        self::closePipedChild($proc, $pipes);
    }

    public function testNonTtyResourceReportsAsNonInteractive(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);

        $input = new ConsoleInput($resource);

        self::assertFalse($input->isInteractive);

        fclose($resource);
    }

    public function testEnableRawModeOnNonTtyThrows(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $resource = fopen('php://memory', 'r+');
            self::assertNotFalse($resource);

            $input = new ConsoleInput($resource);

            $caught = null;
            try {
                $input->enableRawMode($scope);
            } catch (NonInteractiveTtyException $e) {
                $caught = $e;
            }

            self::assertNotNull($caught);
            fclose($resource);
        });
    }

    public function testRestoreIsNoOpWhenRawModeNotEnabled(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $resource = fopen('php://memory', 'r+');
            self::assertNotFalse($resource);

            $input = new ConsoleInput($resource);

            $input->restore($scope);

            self::assertFalse($input->isInteractive);
            fclose($resource);
        });
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private static function spawnPipedChild(string $phpSnippet): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, '-r', $phpSnippet],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($proc);
        self::assertIsResource($pipes[1]);

        return [$proc, $pipes];
    }

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private static function closePipedChild(mixed $proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($proc)) {
            proc_close($proc);
        }
    }
}
