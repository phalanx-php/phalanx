<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Console\Input;

use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Console\Input\NonInteractiveTtyException;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Tests\Support\CoroutineTestCase;

final class ConsoleInputTest extends CoroutineTestCase
{
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
        $this->runScoped(static function (ExecutionScope $scope): void {
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

    public function testReadDrainsAvailableBytesFromSocketPair(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($pair);
            [$reader, $writer] = $pair;

            fwrite($writer, "hello");
            fclose($writer);

            $input = new ConsoleInput($reader);
            $bytes = $input->read($scope, 64, timeout: 2.0);

            self::assertSame('hello', $bytes);

            fclose($reader);
        });
    }

    public function testReadReturnsEmptyOnTimeout(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($pair);
            [$reader, $writer] = $pair;

            $input = new ConsoleInput($reader);
            $bytes = $input->read($scope, 64, timeout: 0.05);

            self::assertSame('', $bytes);

            fclose($reader);
            fclose($writer);
        });
    }

    public function testRestoreIsNoOpWhenRawModeNotEnabled(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            $resource = fopen('php://memory', 'r+');
            self::assertNotFalse($resource);

            $input = new ConsoleInput($resource);

            $input->restore($scope);

            self::assertFalse($input->isInteractive);
            fclose($resource);
        });
    }
}
