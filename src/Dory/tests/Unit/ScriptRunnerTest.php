<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit;

use Phalanx\Dory\DoryConfig;
use Phalanx\Dory\DoryExecutionContext;
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
}
