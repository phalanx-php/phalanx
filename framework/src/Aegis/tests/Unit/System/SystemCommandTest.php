<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\Scope\ExecutionScope;
use Phalanx\System\SystemCommand;
use Phalanx\Testing\PhalanxTestCase;

final class SystemCommandTest extends PhalanxTestCase
{
    public function testEchoesStdoutAndReturnsZeroExit(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): mixed {
            return (new SystemCommand("printf 'hello-aegis'"))($scope);
        });

        self::assertNotNull($result);
        self::assertTrue($result->successful);
        self::assertSame(0, $result->exitCode);
        self::assertSame('hello-aegis', $result->output);
        self::assertGreaterThan(0.0, $result->durationMs);
    }

    public function testNonZeroExitIsCapturedNotThrown(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): mixed {
            return (new SystemCommand("sh -c 'exit 7'"))($scope);
        });

        self::assertNotNull($result);
        self::assertFalse($result->successful);
        self::assertSame(7, $result->exitCode);
    }

    public function testCaptureStderrCombinesStreamsByDefault(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): mixed {
            return (new SystemCommand("sh -c 'printf err-line >&2; exit 0'"))($scope);
        });

        self::assertNotNull($result);
        self::assertTrue($result->successful);
        self::assertStringContainsString('err-line', $result->output);
    }

    public function testFromQuotesArgumentsSafely(): void
    {
        $command = SystemCommand::from('printf', '%s', 'a b');

        self::assertSame("printf '%s' 'a b'", $command->command);
    }

    public function testFromExecutesArgvSafely(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): mixed {
            return SystemCommand::from('printf', 'value: %s', 'hello world')($scope);
        });

        self::assertNotNull($result);
        self::assertTrue($result->successful);
        self::assertSame('value: hello world', $result->output);
    }

    public function testCommandFieldEchoesOnResult(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $scope): mixed {
            return (new SystemCommand("printf done"))($scope);
        });

        self::assertNotNull($result);
        self::assertSame("printf done", $result->command);
    }
}
