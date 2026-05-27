<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit;

use Phalanx\Dory\DoryConfig;
use Phalanx\Dory\DoryExecutionContext;
use Phalanx\Dory\ScriptContextHolder;
use Phalanx\Dory\ScriptRunner;
use Phalanx\Scope\ExecutionScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScriptRunnerTest extends TestCase
{
    #[Test]
    public function executes_script_and_returns_result(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new DoryExecutionContext($scope, __DIR__ . '/../Fixtures/return-42.php', $config);

        $result = ScriptRunner::execute($dory);

        self::assertSame(42, $result);
    }

    #[Test]
    public function script_receives_dory_context(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new DoryExecutionContext($scope, __DIR__ . '/../Fixtures/echo-dory.php', $config);

        ob_start();
        $result = ScriptRunner::execute($dory);
        $output = ob_get_clean();

        self::assertSame(0, $result);
        self::assertSame("leonidas\n", $output);
    }

    #[Test]
    public function script_exception_propagates(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new DoryExecutionContext($scope, __DIR__ . '/../Fixtures/throw-error.php', $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('phalanx broken');

        ScriptRunner::execute($dory);
    }

    #[Test]
    public function dory_outside_context_throws_logic_exception(): void
    {
        ScriptContextHolder::clear();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('dory() called outside of a script context');

        dory();
    }

    #[Test]
    public function context_is_cleared_after_execution(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new DoryExecutionContext($scope, __DIR__ . '/../Fixtures/return-42.php', $config);

        ScriptRunner::execute($dory);

        $this->expectException(\LogicException::class);
        dory();
    }

    #[Test]
    public function context_is_cleared_after_exception(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new DoryExecutionContext($scope, __DIR__ . '/../Fixtures/throw-error.php', $config);

        try {
            ScriptRunner::execute($dory);
        } catch (\RuntimeException) {
        }

        $this->expectException(\LogicException::class);
        dory();
    }
}
